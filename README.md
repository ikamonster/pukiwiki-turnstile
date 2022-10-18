# PukiWiki用プラグイン<br>スパム対策 turnstile.inc.php

クラウドフレア [Turnstile](https://www.cloudflare.com/products/turnstile/) によりスパムを防ぐ[PukiWiki](https://pukiwiki.osdn.jp/)用プラグイン。  

ページ編集・コメント投稿・ファイル添付など、PukiWiki 標準の編集機能をスパムから守ります。  
Turnstileは不審な送信者を自動判定する防壁です。CAPTCHAのような煩わしい入力を要求せず、ウィキの使用感に影響しません。

追加ファイルはこのプラグインのファイル１つだけ。  
PukiWiki 本体の変更も最小限にし、なるべく簡単に導入できるようにしています。  
ただし、JavaScriptを活用する高度な編集系サードパーティ製プラグインとは相性が悪いかもしれません。  
PukiWiki をほぼ素のままで運用し、手軽にスパム対策したいかた向けです。

禁止語句によるスパム判定機能もあります。  
Turnstile を使わず、禁止語句判定のみ用いることも可能です。

<br>

|対象PukiWikiバージョン|対象PHPバージョン|
|:---:|:---:|
|PukiWiki 1.5.4 (UTF-8)|PHP 8.1|

<br>

## インストール

以下の手順に沿って PukiWiki に導入してください。

1. [GitHubページ](https://github.com/ikamonster/pukiwiki-turnstile) からダウンロードした turnstile.inc.php を PukiWiki の plugin ディレクトリに配置する。
2. クラウドフレアの Turnstile ダッシュボードで対象PukiWikiサイトのドメインを登録。「Widget Type」を必ず「**Invisible**（不可視）」とすること。  
そこで割り当てられたサイトキーとシークレットキーとを、本プラグインの定数 PLUGIN_TURNSTILE_SITE_KEY, PLUGIN_TURNSTILE_SECRET_KEY にそれぞれ設定する。
3. PukiWikiスキンファイル（デフォルトは skin/pukiwiki.skin.php）のほぼ末尾、\</body> タグの直前に次のコードを挿入する。  
```PHP
<?php if (exist_plugin_convert('turnstile')) echo do_plugin_convert('turnstile'); // Turnstile plugin ?>
```
4. PukiWikiライブラリファイル lib/plugin.php の「```function do_plugin_action($name)```」関数内、「```$retvar = call_user_func('plugin_' . $name . '_action');```」行の直前に次のコードを挿入する。  
```PHP
if (exist_plugin_action('turnstile') && ($__v = call_user_func_array('plugin_turnstile_action', array($name))['body'])) die_message($__v); // Turnstile plugin
```

<br>

## 詳細

### 設定

ソース内の下記の定数で動作を制御することができます。

|定数名|値|既定値|意味|
|:---|:---:|:---:|:---|
|PLUGIN_TURNSTILE_SITE_KEY|文字列||Turnstileサイトキー。空の場合、Turnstile判定は実施されない|
|PLUGIN_TURNSTILE_SECRET_KEY|文字列||Turnstileシークレットキー。空の場合、Turnstile判定は実施されない|
|PLUGIN_TURNSTILE_API_TIMEOUT|任意の数値|0|Turnstile APIタイムアウト時間（秒）。0ならPHP設定に準じる|
|PLUGIN_TURNSTILE_CENSORSHIP|文字列||投稿禁止語句を表す正規表現|
|PLUGIN_TURNSTILE_CHECK_REFERER|0 or 1|0|1ならリファラーを参照し自サイト以外からの要求を拒否。信頼性が低いため非推奨だが、防壁をわずかでも強化したい場合に用いる|
|PLUGIN_TURNSTILE_ERR_STATUS|HTTPステータスコード|403|拒否時に返すHTTPステータスコード|
|PLUGIN_TURNSTILE_ACTION|文字列|'PukiWiki'|クラウドフレアTurnstile分析画面で表示するアクション名|
|PLUGIN_TURNSTILE_DISABLED|0 or 1|0|1なら本プラグインを無効化（メンテナンス用）|

<br>

### 動作確認

本プラグインが正しく導入されていれば、ページ末尾に「This site is protected by Turnstile and the Cloudflare Privacy Policy and Terms of Service apply.」との文言が表示されます。  
この状態でページ編集やコメント投稿ができていれば正常です。

クラウドフレアの Turnstile ダッシュボードもご確認ください。  
本プラグインにより判定が行われた様子が分析グラフに現れているはずです。

拒否される場合を確認したければ、シークレットキーの値をわざと不正にしてみてください。  
その状態でページ編集などを試みるとエラーになるはずです（正しいテストケースではありませんが）。

なお、本プラグインが正しく導入されていても、レガシーブラウザーでは常に編集に失敗するかもしれませんが、仕様（非対応）としてご了承ください。

<br>

### スパム拒否の仕組み

* ブラウザー側において、JavaScriptによってページ内のすべてのform要素を探しだし、submitボタンがクリックされたらTurnstileトークンを取得して送信パラメーターに含めるよう細工する  
→ 不審者はトークンを得られずに弾かれる  
→ 副作用として、この細工がサードパーティ製プラグインの動作を邪魔する可能性がある
* サーバー側において、受信したリクエストがPOSTメソッドかつ既知のプラグイン呼び出しなら次の判定を行う
  * パラメーターにTurnstileトークンが含まれなければ、不正アクセスとみなしてリクエストを拒否する  
→ フォームを経ず直接プラグインURLにアクセスしてくるロボットは弾かれる
  * Turnstile APIにトークンを送信し、正常応答を得られなければリクエストを拒否する  
→ 不審な送信元IPアドレスや偽造トークンは弾かれる
  * 投稿禁止語句が設定されており、かつテキスト投稿を伴うプラグインであればその内容を判定  
→ 特定の宣伝文句などを含むスパムを弾くことができる。URLを禁止するのが最も広範で効果的だが、不便にもなるので注意

<br>

### 高度な設定：対象プラグインの追加

本プラグインはデフォルトで、PukiWikiに標準添付の編集系プラグインのみをスパム判定の対象としています。  
具体的には次の通り。

``article, attach, bugtrack, comment, edit, freeze, insert, loginform, memo, pcomment, rename, template, tracker, unfreeze, vote``

スパムボットは標準プラグインを標的にすると考えられるため、一般的にはこれで十分なはずです。  
しかし、もし特定のサードパーティ製プラグインを標的として攻撃されていたら、コード内の $targetPlugins 配列にそのプラグイン名を他行に倣って追加してください。  
ただし上述の通り、プラグインの編集・投稿機能が POST メソッドの form 要素かつ submit ボタンで送信する仕組みになっていないと効果がなく、処理内容による相性にも左右されます。

<br>

### ご注意

* 本プラグインv1.0公開時現在、Turnstileはベータ版であり、のちに仕様が変わる可能性があります。
* 標準プラグイン以外の動作確認はしていません。サードパーティ製プラグインによっては機能が妨げられる場合があります。
* JavaScriptが有効でないと動作しません。
* サーバーから Turnstile API へのアクセスに cURL を使用します。
* 閲覧専用（PKWK_READONLY が 1）のウィキにおいては本プラグインは何もしません。

<br>

### 余談

当プラグインを導入すると、Turnstile API と連絡するぶんウィキの書き込みに余計に時間がかかります。
これが気になる場合は、「// Turnstile API呼び出し」コメントのあるIFブロックをまるごとコメントアウトすることで Turnstile API との連絡を省くことができます。
トークンの正当性を検証できなくなり防御力は下がるものの、Turnstile用トークン偽装が一般化でもしないかぎり、これでもほとんどのボットは防げると思います。
勝手な防御力の引き下げは Turnstile およびクラウドフレア社の名誉を損なう恐れがあるためオプションとしては提供しませんが、ご参考までに。

<?php
/**
PukiWiki - Yet another WikiWikiWeb clone.
turnstile.inc.php, v1.0 2022 M. Taniguchi
License: GPL v2 or (at your option) any later version

クラウドフレア Turnstile によるスパム対策プラグイン。

ページ編集・コメント投稿・ファイル添付など、PukiWiki標準の編集機能をスパムから守ります。
クラウドフレアTurnstileは不審な送信者を自動判定する防壁です。CAPTCHAのような煩わしい入力を要求せず、ウィキの使用感に影響しません。

追加ファイルはこのプラグインだけ。PukiWiki本体の変更も最小限にし、なるべく簡単に導入できるようにしています。
ただし、JavaScriptを活用する高度な編集系サードパーティ製プラグインとは相性が悪いかもしれません。
PukiWikiをほぼ素のままで運用し、手軽にスパム対策したいかた向けです。

【導入手順】
以下の手順に沿ってシステムに導入してください。

1) クラウドフレアの「Turnstile」ダッシュボードで対象PukiWikiサイトのドメインを登録。「Widget Type」を必ず「Invisible」とすること。
   そこで割り当てられたサイトキーとシークレットキーとを、本プラグインの定数 PLUGIN_TURNSTILE_SITE_KEY, PLUGIN_TURNSTILE_SECRET_KEY にそれぞれ設定する。

2) PukiWikiスキンファイル skin/pukiwiki.skin.php のほぼ末尾、</body>タグの直前に次のコードを挿入する。
   <?php if (exist_plugin_convert('turnstile')) echo do_plugin_convert('turnstile'); // Turnstile plugin ?>

3) PukiWikiライブラリファイル lib/plugin.php の「function do_plugin_action($name)」関数内、「$retvar = call_user_func('plugin_' . $name . '_action');」行の直前に次のコードを挿入する。
   if (exist_plugin_action('turnstile') && ($__v = call_user_func_array('plugin_turnstile_action', array($name))['body'])) die_message($__v); // Turnstile plugin

【ご注意】
・本プラグインv1.0公開時現在、Turnstileはベータ版であり、のちに仕様が変わる可能性があります。
・PukiWiki 1.5.4／PHP 8.1／UTF-8／主要モダンブラウザーで動作確認済み。旧バージョンでも動くかもしれませんが非推奨です。
・JavaScriptが有効でないと動作しません。
・サーバーからTurnstile APIへのアクセスにcURLを使用します。
・Turnstileについて詳しくはクラウドフレアのドキュメントをご覧ください。https: //developers.cloudflare.com/turnstile/
*/

/////////////////////////////////////////////////
// Turnstile スパム対策プラグイン設定（turnstile.inc.php）
if (!defined('PLUGIN_TURNSTILE_SITE_KEY'))      define('PLUGIN_TURNSTILE_SITE_KEY',      '');         // Turnstileサイトキー。空の場合、Turnstile判定は実施されない
if (!defined('PLUGIN_TURNSTILE_SECRET_KEY'))    define('PLUGIN_TURNSTILE_SECRET_KEY',    '');         // Turnstileシークレットキー。空の場合、Turnstile判定は実施されない
if (!defined('PLUGIN_TURNSTILE_API_TIMEOUT'))   define('PLUGIN_TURNSTILE_API_TIMEOUT',    0);         // Turnstile APIタイムアウト時間（秒）。0なら無指定
if (!defined('PLUGIN_TURNSTILE_CENSORSHIP'))    define('PLUGIN_TURNSTILE_CENSORSHIP',    '');         // 投稿禁止語句を表す正規表現（例：'/((https?|ftp)\:\/\/[\w!?\/\+\-_~=;\.,*&@#$%\(\)\'\[\]]+|宣伝文句)/ui'）
if (!defined('PLUGIN_TURNSTILE_CHECK_REFERER')) define('PLUGIN_TURNSTILE_CHECK_REFERER',  0);         // 1ならリファラーを参照し自サイト以外からの要求を拒否。リファラーは未送や偽装があり得るため頼るべきではないが、使える局面はあるかもしれない
if (!defined('PLUGIN_TURNSTILE_ERR_STATUS'))    define('PLUGIN_TURNSTILE_ERR_STATUS',     403);       // 拒否時に返すHTTPステータスコード
if (!defined('PLUGIN_TURNSTILE_ACTION'))        define('PLUGIN_TURNSTILE_ACTION',        'PukiWiki'); // クラウドフレアTurnstile分析画面で表示するアクション名
if (!defined('PLUGIN_TURNSTILE_DISABLED'))      define('PLUGIN_TURNSTILE_DISABLED',       0);         // 1なら本プラグインを無効化。メンテナンス用


// プラグイン出力
function plugin_turnstile_convert() {
	$enabled = (PLUGIN_TURNSTILE_SITE_KEY && PLUGIN_TURNSTILE_SECRET_KEY);	// Turnstile有効フラグ

	// 本プラグインが無効か書き込み禁止なら何もしない
	if (PLUGIN_TURNSTILE_DISABLED || (!$enabled && !PLUGIN_TURNSTILE_CENSORSHIP) || PKWK_READONLY || !PKWK_ALLOW_JAVASCRIPT) return '';

	// 二重起動禁止
	static	$included = false;
	if ($included) return '';
	$included = true;

	$badge = (!$enabled)? '' : '<div id="_p_turnstile_terms">This site is protected by Turnstile and the Cloudflare <a href="' . 'https://www.cloudflare.com/privacypolicy/" rel="noopener nofollow external">Privacy Policy</a> and <a href="' . 'https://www.cloudflare.com/website-terms/" rel="noopener nofollow external">Terms of Service</a> apply.</div>';

	// JavaScript
	$siteKey = PLUGIN_TURNSTILE_SITE_KEY;
	$action = PLUGIN_TURNSTILE_ACTION;
	$jsSrc = 'https:'.'//challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__PluginTurnstile_onloadTurnstileCallback';
	$enabled = ($enabled)? 'true' : 'false';
	$js = <<<EOT
<script>
'use strict';

let	__PluginTurnstile_Instance__ = null;

window.addEventListener('DOMContentLoaded', () => {
	__PluginTurnstile_Instance__ = new __PluginTurnstile__();
});

function __PluginTurnstile_onloadTurnstileCallback() {
	__PluginTurnstile_Instance__.setup();
}

var	__PluginTurnstile__ = function() {
	const	self = this;
	this.timer = null;
	this.libLoaded = false;
	this.observer = null;

	// DOMを監視し、もしページ内容が動的に変更されたら再設定する（モダンブラウザーのみ対応）
	if (!self.observer) self.observer = new MutationObserver((mutations) => { mutations.forEach((mutation) => { if (mutation.type == 'childList') self.update(); }); });
	if (self.observer) {
		const target = document.getElementsByTagName('body')[0];
		if (target) self.observer.observe(target, { childList: true, subtree: true });
	}

	// 設定更新
	this.update();
};

// 設定更新
__PluginTurnstile__.prototype.update = function() {
	const	self = this;
	if (this.timer) clearTimeout(this.timer);
	this.timer = setTimeout(() => {
		// form要素があればTurnstileを設定
		const	elements = document.getElementsByTagName('form');
		if (elements.length > 0) {
			self.loadLib();
			self.setup();
		}
		self.timer = null;
	}, 50);
};

// Turnstileコードロード
__PluginTurnstile__.prototype.loadLib = function() {
	if (!this.libLoaded) {
		this.libLoaded = true;
		const	scriptElement = document.createElement('script');
		scriptElement.src = '${jsSrc}';
		scriptElement.setAttribute('async', 'async');
		scriptElement.setAttribute('defer', 'defer');
		document.body.appendChild(scriptElement);
	}
};

// Turnstile設定
__PluginTurnstile__.prototype.setup = function() {
	const	self = this;

	if (!window.turnstile) return;

	// 全form要素を走査
	const	elements = document.getElementsByTagName('form');
	for (let i = elements.length - 1; i >= 0; --i) {
		const	form = elements[i];
		if (!form.hasAttribute('data-turnstile-plugin')) {
			form.setAttribute('data-turnstile-plugin', i);

			// formにTurnstile要素を挿入
			const	turnstileElement = document.createElement('turnstile');
			turnstileElement.setAttribute('id', '__PluginTurnstile__' + i);
			turnstileElement.setAttribute('inert', 'true');
			turnstileElement.setAttribute('style', 'position:absolute;width:0px;min-width:0px;height:0px;min-height:0px;overflow:hidden;visibility:hidden');
			form.appendChild(turnstileElement);

			// form内全submitボタンを走査しクリックイベントを設定
			const	eles = form.querySelectorAll('input[type="submit"]');
			if (eles.length > 0) {
				for (var j = eles.length - 1; j >= 0; --j) eles[j].addEventListener('click', self.submit, false);

				// こちらのタイミングで送信するため、既定の送信イベントを止めておく
				form.addEventListener('submit', self.stopSubmit, false);
			}
		}
	}
};

// 送信防止
__PluginTurnstile__.prototype.stopSubmit = function(e) {
	e.preventDefault();
	e.stopPropagation();
	return false;
};

// クリック時送信処理
__PluginTurnstile__.prototype.submit = function(e) {
	let	form;
	if (this.closest) {
		form = this.closest('form');
	} else {
		for (form = this.parentNode; form; form = form.parentNode) if (form.nodeName.toLowerCase() == 'form') break;	// 旧ブラウザー対策
	}

	// クリックされたsubmitボタンのname,value属性をhiddenにコピー（submitボタンが複数ある場合への対処）
	if (form) {
		const	nameEle = form.querySelector('.__plugin_turnstile_submit__');
		const	name = this.getAttribute('name');
		if (name) {
			const	value = this.getAttribute('value');
			if (!nameEle) {
				form.insertAdjacentHTML('beforeend', '<input type="hidden" class="__plugin_turnstile_submit__" name="' + name + '" value="' + value + '"/>');
			} else {
				nameEle.setAttribute('name', name);
				nameEle.setAttribute('value', value);
			}
		} else
		if (nameEle) {
			if (nameEle.remove) nameEle.remove();
			else nameEle.parentNode.removeChild(nameEle);
		}

		if (${enabled}) {
			// Turnstileトークンを取得してフォーム送信
			const	options = {
				sitekey: '${siteKey}',
				callback: function(token) { form.submit() }
			};
			if ('${action}' != '') options.action = '${action}';
			turnstile.render('turnstile#__PluginTurnstile__' + form.getAttribute('data-turnstile-plugin'), options);
		} else {
			// Turnstile無効なら即フォーム送信
			form.submit();
		}
	}
	return false;
};
</script>
EOT;

	return $badge . $js;
}


// 受信リクエスト確認
function plugin_turnstile_action() {
	$result = '';	// 送信者判定結果（許可：空, 拒否：エラーメッセージ）
	$enabled = (PLUGIN_TURNSTILE_SITE_KEY && PLUGIN_TURNSTILE_SECRET_KEY);	// Turnstile有効フラグ

	// 機能有効かつPOSTメソッド？
	if (!PLUGIN_TURNSTILE_DISABLED && ($enabled || PLUGIN_TURNSTILE_CENSORSHIP) && !PKWK_READONLY && $_SERVER['REQUEST_METHOD'] == 'POST') {
		/* 【対象プラグイン設定テーブル】
		   Turnstile判定の対象とするプラグインを列挙する配列。
		   name   … プラグイン名
		   censor … 検閲対象パラメーター名
		   vars   … 併送パラメーター名
		*/
		$targetPlugins = array(
			array('name' => 'article',  'censor' => 'msg'),
			array('name' => 'attach'),
			array('name' => 'bugtrack', 'censor' => 'body'),
			array('name' => 'comment',  'censor' => 'msg'),
			array('name' => 'edit',     'censor' => 'msg', 'vars' => 'write'),	// editプラグインはwriteパラメーター併送（ページ更新）時のみ対象
			array('name' => 'freeze'),
			array('name' => 'insert',   'censor' => 'msg'),
			array('name' => 'loginform'),
			array('name' => 'memo',     'censor' => 'msg'),
			array('name' => 'pcomment', 'censor' => 'msg'),
			array('name' => 'rename'),
			array('name' => 'template'),
			array('name' => 'tracker',  'censor' => 'Messages'),
			array('name' => 'unfreeze'),
			array('name' => 'vote'),
		);

		global	$vars;
		list($name) = func_get_args();

		foreach ($targetPlugins as $target) {
			if ($target['name'] != $name) continue;	// プラグイン名一致？
			if (!isset($target['vars']) || isset($vars[$target['vars']])) {	// クエリーパラメーター未指定、または指定名が含まれる？
				if ($enabled && (!isset($vars['cf-turnstile-response']) || $vars['cf-turnstile-response'] == '')) {	// Turnstileトークンあり？
					// トークンのない不正要求なら送信者を拒否
					$result = 'Rejected by Clougflare Turnstile';
				} else
				if (PLUGIN_TURNSTILE_CHECK_REFERER && strpos($_SERVER['HTTP_REFERER'], get_script_uri()) === false) {
					// 自サイト以外からのアクセスを拒否
					$result = 'Deny access';
				} else {
					// 検閲対象パラメーターあり？
					if (PLUGIN_TURNSTILE_CENSORSHIP && isset($target['censor']) && isset($vars[$target['censor']])) {
						// 投稿禁止語句が含まれていたら受信拒否
						if (preg_match(PLUGIN_TURNSTILE_CENSORSHIP, $vars[$target['censor']])) {
							$result = 'Forbidden word detected';
							break;
						}
					}

					// Turnstile API呼び出し
					if ($enabled) {
						$ch = curl_init('https:'.'//challenges.cloudflare.com/turnstile/v0/siteverify');
						curl_setopt($ch, CURLOPT_POST, true);
						curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('secret' => PLUGIN_TURNSTILE_SECRET_KEY, 'response' => $vars['cf-turnstile-response'])));
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
						if (PLUGIN_TURNSTILE_API_TIMEOUT > 0) curl_setopt($ch, CURLOPT_TIMEOUT, PLUGIN_TURNSTILE_API_TIMEOUT);
						$data = json_decode(curl_exec($ch));
						curl_close($ch);

						// 失敗なら送信者を拒否
						if (!$data->success) $result = 'Rejected by Clougflare Turnstile';
					}
				}
				break;
			}
		}

		// エラー用のHTTPステータスコードを設定
		if ($result && PLUGIN_TURNSTILE_ERR_STATUS) http_response_code(PLUGIN_TURNSTILE_ERR_STATUS);
	}

	return array('msg' => 'turnstile', 'body' => $result);
}

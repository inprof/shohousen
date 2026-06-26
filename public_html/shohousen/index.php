<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
View::header('TOP', ['body_class' => 'page-top']);
?>
<section class="hero card">
  <div class="hero-copy">
    <p class="eyebrow">薬局業務支援クラウド</p>
    <h1>調剤業務をもっと効率的に<br>もっと正確に</h1>
    <p>AIとデータ連携で、薬局の受付・確認・検索業務をサポートするWebシステムです。</p>
    <div class="button-row">
      <a class="btn primary" href="<?= h(app_url('/login.php')) ?>">ログイン</a>
      <a class="btn ghost" href="#service">サービス紹介を見る</a>
    </div>
  </div>
  <div class="hero-visual" aria-label="薬剤師のイメージ">
    <div class="photo-card">
      <div class="photo-face">👩‍⚕️</div>
      <div>
        <strong>受付からQR出力まで</strong>
        <span>スマホ・タブレット優先UI</span>
      </div>
    </div>
  </div>
</section>

<section class="top-grid">
  <div class="card" id="service">
    <h2>お知らせ</h2>
    <ul class="news-list">
      <li><time>2024/05/20</time>システムメンテナンスのお知らせ（5/25 22:00〜）</li>
      <li><time>2024/05/15</time>新機能「在庫管理」の提供を開始しました</li>
      <li><time>2024/04/30</time>ゴールデンウィーク期間中のサポートについて</li>
    </ul>
  </div>
  <div class="card support-card">
    <h2>サポート・ヘルプ</h2>
    <p>ご利用方法やよくある質問はこちら</p>
    <a class="btn ghost" href="#">ヘルプページへ</a>
  </div>
</section>
<?php View::footer(); ?>

<?php
$to      = 'hello@mapleboost.ca';
$sent    = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $message = trim($_POST['message'] ?? '');
    $hp      = trim($_POST['website'] ?? '');  // honeypot

    if ($hp !== '') {
        $sent = true; // silently swallow bots
    } elseif (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$message) {
        $error = 'Please fill in your name, a valid email, and a message.';
    } else {
        $subject = '[MapleBoost contact] ' . substr($name, 0, 60);
        $body    = "From: $name <$email>\n\n$message\n";
        $headers = "From: noreply@mapleboost.ca\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        @mail($to, $subject, $body, $headers);
        $sent = true;
    }
}
?>
<!doctype html>
<html lang="en-CA">
<head>
<!--#include virtual="/inc/head.html" -->
<title>Contact MapleBoost</title>
<meta name="description" content="Get in touch with MapleBoost - corrections, tips, partnership inquiries.">
<link rel="canonical" href="https://mapleboost.ca/contact">

<meta property="og:title" content="Contact MapleBoost">
<meta property="og:description" content="Get in touch with MapleBoost - corrections, tips, partnership inquiries.">
<meta property="og:url" content="https://mapleboost.ca/contact">

<meta name="twitter:title" content="Contact MapleBoost">
<meta name="twitter:description" content="Get in touch with MapleBoost - corrections, tips, partnership inquiries.">

</head>
<body>
<?php include __DIR__ . '/inc/nav.html'; ?>
<main id="content">

<div class="container">
  <article class="article-wrap">
    <div class="article">
      <span class="eyebrow">Contact</span>
      <h1>Contact us</h1>
      <?php if ($sent): ?>
        <p class="lead">Thanks - your message is on its way. We reply within two business days.</p>
      <?php else: ?>
        <p class="lead">Corrections, tips, partnership inquiries, or anything else - drop a note.</p>
        <?php if ($error): ?><div class="disclosure"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" action="/contact" style="max-width:520px">
          <p><label>Name<br><input name="name" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px"></label></p>
          <p><label>Email<br><input type="email" name="email" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px"></label></p>
          <p><label>Message<br><textarea name="message" rows="6" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px"></textarea></label></p>
          <p style="position:absolute;left:-9999px"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></p>
          <p><button class="btn btn-primary" type="submit">Send</button></p>
        </form>
      <?php endif; ?>
      <p class="muted">Or email <a href="mailto:hello@mapleboost.ca">hello@mapleboost.ca</a>.</p>
    </div>
  </article>
</div>

<?php include __DIR__ . '/inc/footer.html'; ?>

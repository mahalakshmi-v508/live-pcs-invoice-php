<?php
/**
 * The password reset now runs inside the login page modal (index.php).
 * This file is kept only so old links/bookmarks do not 404.
 */

require_once __DIR__ . '/session.php';
ensureSessionStarted();

header('Location: index.php?forgot=1', true, 302);
exit;

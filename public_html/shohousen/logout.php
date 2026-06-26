<?php
require_once dirname(__DIR__, 2) . '/private/shohousen/app/bootstrap.php';
Auth::logout();
redirect('/index.php');

<?php
require __DIR__ . '/../db.php';
session_start();
session_destroy();
f1hub_json(['ok' => true]);

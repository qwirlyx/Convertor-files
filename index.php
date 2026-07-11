<?php
session_start();

if (empty($_SESSION['role'])) {
    header('Location: auth.php');
    exit;
}

readfile(__DIR__ . '/index.html');
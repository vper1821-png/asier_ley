<?php
require '/var/www/html/config.php';
require '/var/www/html/routes/compliance.php';
$token='eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiI5YjUyMDEzNzFmYjEwNmQwN2UxMDdkMjQiLCJpYXQiOjE3ODczNjI5NDIsImV4cCI6MTc4Nzk2Nzc0Mn0.G3_IIr0G__8X6oNyQ4QGGhO19JHcoMekFgX4GOp52F0';
$_GET['token']=$token;
generatePublicPolicy();
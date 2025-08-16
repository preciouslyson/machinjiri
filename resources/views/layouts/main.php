<?php use Mlangeni\Machinjiri\Core\Views\View; ?>
<!DOCTYPE html>
<html>
<head>
  <title><?php View::yield('title'); ?></title>
  <link rel="stylesheet" href="../vendor/twbs/bootstrap/dist/css/bootstrap.css">
  <link rel="stylesheet" href="../vendor/components/font-awesome/css/all.min.css">
</head>
<body>
  <div class="content">
    <?php View::yield('content'); ?>
  </div>
</body>
</html>
<!DOCTYPE html>
<html lang="<?php echo $sf_user->getCulture() ?>" dir="<?php echo sfCultureInfo::getInstance($sf_user->getCulture())->direction ?>">
  <head>
    <?php include_http_metas() ?>
    <?php include_metas() ?>
    <?php include_title() ?>
    <link rel="shortcut icon" href="<?php echo public_path('favicon.ico') ?>"/>
    <?php echo stylesheet_tag('/plugins/arDominionPlugin/css/min.css') ?>
    <?php echo javascript_include_tag('/plugins/arDrmcPlugin/frontend/dist/Binder.vendor.js') ?>
    <?php echo javascript_include_tag('/plugins/arDrmcPlugin/frontend/dist/Binder.app.js') ?>
  </head>
  <body ng-app="drmc" class="drmc" ng-controller="BodyCtrl">

    <!-- View placeholder -->
    <ui-view autoscroll="false"/>

  </body>
</html>

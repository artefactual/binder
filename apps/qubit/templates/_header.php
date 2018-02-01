<?php // echo get_component('default', 'updateCheck') ?>

<header id="top-bar">

  <h1 id="site-name">
    <?php echo link_to(__('Binder'), '@homepage', array('rel' => 'home', 'title' => __('Home'))) ?>
  </h1>

  <nav>

    <?php echo get_component('menu', 'userMenu') ?>

    <div id="home-link" class="top-menu-link" data-toggle="tooltip" data-title="<?php echo __('Home') ?>">
      <?php echo link_to(__('Home'), '@homepage', array('class' => 'top-item', 'title' => __('Home'))) ?>
    </div>

  </nav>

  </section>

</header>

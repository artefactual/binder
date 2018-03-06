<?php decorate_with('layout_3col') ?>
<?php use_helper('Text') ?>

<?php slot('sidebar') ?>
  <div class="sidebar-lowering-sort">
    <?php echo get_component('term', 'treeView', array('browser' => false)) ?>
  </div>
<?php end_slot() ?>

<?php slot('title') ?>

  <h1><?php echo render_title($resource) ?></h1>

  <?php if (isset($errorSchema)): ?>
    <div class="messages error">
      <ul>
        <?php foreach ($errorSchema as $error): ?>
          <li><?php echo $error ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (QubitTerm::ROOT_ID != $resource->parentId): ?>
    <?php echo include_partial('default/breadcrumb', array('resource' => $resource, 'objects' => $resource->getAncestors()->andSelf()->orderBy('lft'))) ?>
  <?php endif; ?>

<?php end_slot() ?>

<?php slot('before-content') ?>
  <?php echo get_component('default', 'translationLinks', array('resource' => $resource)) ?>
<?php end_slot() ?>

<?php slot('context-menu') ?>
  <div class="sidebar">
    <?php echo get_partial('term/format', array('resource' => $resource)) ?>
  </div>
<?php end_slot() ?>

<?php slot('content') ?>

  <div id="content">

    <?php echo render_show(__('Taxonomy'), link_to(render_title($resource->taxonomy), array($resource->taxonomy, 'module' => 'taxonomy'))) ?>

    <div class="field">
      <h3><?php echo __('Code') ?></h3>
      <div>
        <?php echo $resource->code ?>
        <?php if (!empty($resource->code) && QubitTaxonomy::PLACE_ID == $resource->taxonomy->id): ?>
          <?php echo image_tag('http://maps.googleapis.com/maps/api/staticmap?zoom=13&size=300x300&sensor=false&center='.$resource->code, array('class' => 'static-map')) ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="field">
      <h3><?php echo __('Scope note(s)') ?></h3>
      <div>
        <ul>
          <?php foreach ($resource->getNotesByType(array('noteTypeId' => QubitTerm::SCOPE_NOTE_ID)) as $item): ?>
            <li><?php echo render_value($item->getContent(array('cultureFallback' => true))) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="field">
      <h3><?php echo __('Source note(s)') ?></h3>
      <div>
        <ul>
          <?php foreach ($resource->getNotesByType(array('noteTypeId' => QubitTerm::SOURCE_NOTE_ID)) as $item): ?>
            <li><?php echo render_value($item->getContent(array('cultureFallback' => true))) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="field">
      <h3><?php echo __('Display note(s)') ?></h3>
      <div>
        <ul>
          <?php foreach ($resource->getNotesByType(array('noteTypeId' => QubitTerm::DISPLAY_NOTE_ID)) as $item): ?>
            <li><?php echo render_value($item->getContent(array('cultureFallback' => true))) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="field">
      <h3><?php echo __('Hierarchical terms') ?></h3>
      <div>

        <?php if (QubitTerm::ROOT_ID != $resource->parent->id): ?>
          <?php echo render_show(render_title($resource), __('BT %1%', array('%1%' => link_to(render_title($resource->parent), array($resource->parent, 'module' => 'term'))))) ?>
        <?php endif; ?>

        <div class="field">
          <h3><?php echo render_title($resource) ?></h3>
          <div>
            <ul>
              <?php foreach ($resource->getChildren(array('sortBy' => 'name')) as $item): ?>
                <li><?php echo __('NT %1%', array('%1%' => link_to(render_title($item), array($item, 'module' => 'term')))) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

      </div>
    </div>

    <div class="field">
      <h3><?php echo __('Equivalent terms') ?></h3>
      <div>

        <div class="field">
          <h3><?php echo render_title($resource) ?></h3>
          <div>
            <ul>
              <?php foreach ($resource->otherNames as $item): ?>
                <li><?php echo __('UF %1%', array('%1%' => render_title($item))) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

      </div>
    </div>

    <?php if (0 < count($converseTerms = QubitRelation::getBySubjectOrObjectId($resource->id, array('typeId' => QubitTerm::CONVERSE_TERM_ID)))): ?>
      <?php echo render_show(__('Converse term'), link_to(render_title($converseTerms[0]->getOpposedObject($resource)), array($converseTerms[0]->getOpposedObject($resource), 'module' => 'term'))) ?>
    <?php endif; ?>

    <div class="field">
      <h3><?php echo __('Associated terms') ?></h3>
      <div>

        <div class="field">
          <h3><?php echo render_title($resource) ?></h3>
          <div>
            <ul>
              <?php foreach (QubitRelation::getBySubjectOrObjectId($resource->id, array('typeId' => QubitTerm::TERM_RELATION_ASSOCIATIVE_ID)) as $item): ?>
                <li><?php echo __('RT %1%', array('%1%' => link_to(render_title($item->getOpposedObject($resource->id)), array($item->getOpposedObject($resource->id), 'module' => 'term')))) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

      </div>
    </div>
  </div>

  <section class="actions">

    <ul>

      <?php if ((QubitAcl::check($resource, 'update') || QubitAcl::check($resource, 'translate')) && !QubitTerm::isProtected($resource->id)): ?>
        <li><?php echo link_to (__('Edit'), array($resource, 'module' => 'term', 'action' => 'edit'), array('class' => 'c-btn c-btn-submit')) ?></li>
      <?php endif; ?>

      <?php if (QubitAcl::check($resource, 'delete') && !QubitTerm::isProtected($resource->id)): ?>
        <li><?php echo link_to (__('Delete'), array($resource, 'module' => 'term', 'action' => 'delete'), array('class' => 'c-btn c-btn-delete')) ?></li>
      <?php endif; ?>

      <?php if (QubitAcl::check($resource->taxonomy, 'createTerm')): ?>
        <li><?php echo link_to(__('Add new'), array('module' => 'term', 'action' => 'add', 'parent' => url_for(array($resource, 'module' => 'term')), 'taxonomy' => url_for(array($resource->taxonomy, 'module' => 'taxonomy'))), array('class' => 'c-btn')) ?></li>
      <?php endif; ?>

    </ul>

  </section>

<?php end_slot() ?>

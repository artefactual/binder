<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Add Binder-specific data
 *
 * @package    symfony
 * @subpackage task
 */
class arDrmcBootstrapTask extends sfBaseTask
{
  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', 'qubit'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'cli'),
    ));

    $this->namespace = 'drmc';
    $this->name = 'bootstrap';

    $this->briefDescription = 'Bootstrap Binder database';
    $this->detailedDescription = <<<EOF
The [drmc:bootstrap|INFO] task adds the necessary initial data to your database
EOF;
  }

  public function execute($arguments = array(), $options = array())
  {
    sfContext::createInstance($this->configuration);
    new sfDatabaseManager($this->configuration);

    $this->addLevelsOfDescriptions();
    $this->addTaxonomies();
    $this->addTerms();
    $this->addSavedQueryTypes();
  }

  protected function addLevelsOfDescriptions()
  {
    // Remove AtoM's defaults
    foreach (QubitTaxonomy::getTaxonomyTerms(QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID, array('level' => 'top')) as $item)
    {
      $target = array('Fonds', 'Subfonds', 'Collection', 'Series', 'Subseries', 'File', 'Item', 'Part');
      $name = $item->getName(array('culture' => 'en'));
      if (in_array($name, $target))
      {
        $item->delete();
      }
    }

    // Levels of description specific for Binder
    $levels = array(
      'Artwork record',
      'Description',
      'Component',
      'Artist supplied master',
      'Artist verified proof',
      'Archival master',
      'Exhibition format',
      'Documentation',
      'Miscellaneous',
      'Supporting technology record',
      'AIP',
      'Digital object'
    );

    // Find a specific level of description by its name (in English)
    $find = function($name)
    {
      $criteria = new Criteria;
      $criteria->add(QubitTerm::TAXONOMY_ID, QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID);
      $criteria->addJoin(QubitTerm::ID, QubitTermI18n::ID);
      $criteria->add(QubitTermI18n::NAME, $name);
      $criteria->add(QubitTermI18n::CULTURE, 'en');

      return null !== QubitTerm::getOne($criteria);
    };

    foreach ($levels as $level)
    {
      // Don't duplicate
      if (true === $find($level))
      {
        continue;
      }

      $term = new QubitTerm;
      $term->name  = $level;
      $term->taxonomyId = QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID;
      $term->parentId = QubitTerm::ROOT_ID;
      $term->culture = 'en';
      $term->save();
    }
  }

  protected function addTaxonomies()
  {
    $taxonomies = array(
      'Classifications',
      'Departments',
      'Component types',
      'Supporting technologies relation types',
      'Associative relationship types');

    foreach ($taxonomies as $name)
    {
      $criteria = new Criteria;
      $criteria->add(QubitTaxonomy::PARENT_ID, QubitTaxonomy::ROOT_ID);
      $criteria->add(QubitTaxonomyI18n::NAME, $name);
      $criteria->add(QubitTaxonomyI18n::CULTURE, 'en');
      $criteria->addJoin(QubitTaxonomy::ID, QubitTaxonomyI18n::ID);
      if (null !== QubitTaxonomy::getOne($criteria))
      {
        continue;
      }

      $taxonomy = new QubitTaxonomy;
      $taxonomy->parentId = QubitTaxonomy::ROOT_ID;
      $taxonomy->name = $name;
      $taxonomy->culture = 'en';
      $taxonomy->save();
    }
  }

  protected function addTerms()
  {
    $terms = array(
      array(
        'parentId' => QubitTerm::ROOT_ID,
        'taxonomyId' => QubitTaxonomy::NOTE_TYPE_ID,
        'name' => 'InstallComments'
      ),
      array(
        'parentId' => QubitTerm::ROOT_ID,
        'taxonomyId' => QubitTaxonomy::NOTE_TYPE_ID,
        'name' => 'PrepComments'
      ),
      array(
        'parentId' => QubitTerm::ROOT_ID,
        'taxonomyId' => QubitTaxonomy::NOTE_TYPE_ID,
        'name' => 'StorageComments'
      ),
      array(
        'parentId' => QubitTerm::ROOT_ID,
        'taxonomyId' => QubitTaxonomy::RELATION_TYPE_ID,
        'name' => 'Supporting technology relation types'
      )
    );

    foreach ($terms as $item)
    {
      $criteria = new Criteria;
      $criteria->add(QubitTerm::PARENT_ID, $item['parentId']);
      $criteria->add(QubitTerm::TAXONOMY_ID, $item['taxonomyId']);
      $criteria->add(QubitTermI18n::NAME, $item['name']);
      $criteria->add(QubitTermI18n::CULTURE, 'en');
      $criteria->addJoin(QubitTerm::ID, QubitTermI18n::ID);
      if (null !== QubitTerm::getOne($criteria))
      {
        continue;
      }

      $term = new QubitTerm;
      $term->parentId = $item['parentId'];
      $term->taxonomyId = $item['taxonomyId'];
      $term->sourceCulture = 'en';
      $term->setName($item['name'], array('culture' => 'en'));
      $term->save();
    }

    $criteria = new Criteria;
    $criteria->add(QubitTaxonomyI18n::NAME, 'Supporting technologies relation types');
    $criteria->add(QubitTaxonomyI18n::CULTURE, 'en');
    $criteria->addJoin(QubitTaxonomy::ID, QubitTaxonomyI18n::ID);
    if (null !== $taxonomy = QubitTaxonomy::getOne($criteria))
    {
      foreach (array(
        'isPartOf',
        'isFormatOf',
        'isVersionOf',
        'references',
        'requires') as $type)
      {
        // Make sure that the term hasn't been added already
        $criteria = new Criteria;
        $criteria->add(QubitTerm::PARENT_ID, QubitTerm::ROOT_ID);
        $criteria->add(QubitTerm::TAXONOMY_ID, $taxonomy->id);
        $criteria->add(QubitTermI18n::CULTURE, 'en');
        $criteria->add(QubitTermI18n::NAME, $type);
        $criteria->addJoin(QubitTerm::ID, QubitTermI18n::ID);
        if (null !== QubitTerm::getOne($criteria))
        {
          continue;
        }

        $term = new QubitTerm;
        $term->parentId = QubitTerm::ROOT_ID;
        $term->taxonomyId = $taxonomy->id;
        $term->sourceCulture = 'en';
        $term->setName($type, array('culture' => 'en'));
        $term->save();
      }
    }

    $criteria = new Criteria;
    $criteria->add(QubitTaxonomyI18n::NAME, 'Associative relationship types');
    $criteria->add(QubitTaxonomyI18n::CULTURE, 'en');
    $criteria->addJoin(QubitTaxonomy::ID, QubitTaxonomyI18n::ID);
    if (null !== $taxonomy = QubitTaxonomy::getOne($criteria))
    {
      foreach (array(              // Reciprocity:
        'hasPart',                 //    isPartOf
        'hasFormat',               //    isFormatOf
        'hasVersion',              //    isVersionOf
        'isReferencedBy',          //    references
        'isReplacedBy',            //    replaces
        'isRequiredBy') as $type)  //    requires
      {
        // Make sure that the term hasn't been added already
        $criteria = new Criteria;
        $criteria->add(QubitTerm::PARENT_ID, QubitTerm::ROOT_ID);
        $criteria->add(QubitTerm::TAXONOMY_ID, $taxonomy->id);
        $criteria->add(QubitTermI18n::CULTURE, 'en');
        $criteria->add(QubitTermI18n::NAME, $type);
        $criteria->addJoin(QubitTerm::ID, QubitTermI18n::ID);
        if (null !== QubitTerm::getOne($criteria))
        {
          continue;
        }

        $term = new QubitTerm;
        $term->parentId = QubitTerm::ROOT_ID;
        $term->taxonomyId = $taxonomy->id;
        $term->sourceCulture = 'en';
        $term->setName($type, array('culture' => 'en'));
        $term->save();
      }
    }
  }

  protected function addSavedQueryTypes()
  {
    $criteria = new Criteria;
    $criteria->add(QubitTaxonomy::PARENT_ID, QubitTaxonomy::ROOT_ID);
    $criteria->add(QubitTaxonomyI18n::NAME, 'Saved query types');
    $criteria->add(QubitTaxonomyI18n::CULTURE, 'en');
    $criteria->addJoin(QubitTaxonomy::ID, QubitTaxonomyI18n::ID);
    if (null !== QubitTaxonomy::getOne($criteria))
    {
      return;
    }

    $taxonomy = new QubitTaxonomy;
    $taxonomy->parentId = QubitTaxonomy::ROOT_ID;
    $taxonomy->name = 'Saved query types';
    $taxonomy->culture = 'en';
    $taxonomy->save();

    if (isset($taxonomy->id))
    {
      foreach (array('Search', 'Report') as $type)
      {
        // Make sure that the term hasn't been added already
        $criteria = new Criteria;
        $criteria->add(QubitTerm::PARENT_ID, QubitTerm::ROOT_ID);
        $criteria->add(QubitTerm::TAXONOMY_ID, $taxonomy->id);
        $criteria->add(QubitTermI18n::CULTURE, 'en');
        $criteria->add(QubitTermI18n::NAME, $type);
        $criteria->addJoin(QubitTerm::ID, QubitTermI18n::ID);
        if (null !== QubitTerm::getOne($criteria))
        {
          continue;
        }

        $term = new QubitTerm;
        $term->parentId = QubitTerm::ROOT_ID;
        $term->taxonomyId = $taxonomy->id;
        $term->sourceCulture = 'en';
        $term->setName($type, array('culture' => 'en'));
        $term->save();
      }
    }
  }
}

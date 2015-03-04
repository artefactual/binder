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
 * Delete AIP task
 *
 * @package    symfony
 * @subpackage task
 */
class arDrmcDeleteAipTask extends sfBaseTask
{
  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', 'qubit'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'cli'),
      new sfCommandOption('verbose', 'v', sfCommandOption::PARAMETER_NONE, 'If passed, progress is displayed for each object indexed')));

    $this->addArguments(array(
      new sfCommandArgument('uuid', null, sfCommandArgument::REQUIRED, 'UUID of the artwork record')
    ));

    $this->namespace = 'binder';
    $this->name = 'delete-aip';

    $this->briefDescription = 'Delete AIP and its descendants and update the ES index for its ancestors';
    $this->detailedDescription = <<<EOF
The [drmc:delete-aip|INFO] task deletes and AIP record and its descendants
and updates the ES index for its related objects given the AIP UUID.
EOF;
  }

  public function execute($arguments = array(), $options = array())
  {
    // Bootstrap
    $configuration = ProjectConfiguration::getApplicationConfiguration('qubit', 'test', false);
    $sf_context = sfContext::createInstance($configuration);
    $databaseManager = new sfDatabaseManager($configuration);
    $conn = $databaseManager->getDatabase('propel')->getConnection();

    // Start transaction
    $conn->beginTransaction();

    try
    {
      $this->run($arguments, $options);

      $conn->commit();
    }
    catch (Exception $e)
    {
      $conn->rollback();

      throw $e;
    }
  }

  public function run($arguments = array(), $options = array())
  {
    if (null === $aip = QubitAip::getByUuid($arguments['uuid']))
    {
      throw new sfException('AIP not found');
    }

    $this->logSection('INFO', 'Starting process ...');

    // Get related objects for later update in ES
    $relatedObjectsIds = array();
    $relations = QubitRelation::getRelationsBySubjectId($aip->id, array('typeId' => QubitTerm::AIP_RELATION_ID));
    foreach ($relations as $item)
    {
      if (isset($item->objectId))
      {
        $relatedObjectsIds[] = $item->objectId;
      }
    }

    // Add parOf to those related objects
    if (isset($aip->partOf))
    {
      $relatedObjectsIds[] = $aip->partOf;
    }

    // Check if AIP IO for the context browser has been removed
    // There isn't a relation between them but the AIP's digital obejcts are child of the AIP IO
    $criteria = new Criteria;
    $criteria->addJoin(QubitInformationObject::ID, QubitProperty::OBJECT_ID);
    $criteria->addJoin(QubitProperty::ID, QubitPropertyI18n::ID);
    $criteria->add(QubitProperty::NAME, 'aipUUID');
    $criteria->add(QubitPropertyI18n::VALUE, $aip->uuid);

    if (null !== $aipDigitalObject = QubitInformationObject::getOne($criteria))
    {
      $aipIo = $aipDigitalObject->parent;

      // Delete AIP files and CB node
      $this->logSection('INFO', 'Deleting digital obejcts and context browser node ...');

      foreach ($aipIo->descendants->andSelf()->orderBy('rgt') as $item)
      {
        // Delete related digitalObjects
        foreach ($item->digitalObjects as $digitalObject)
        {
          $digitalObject->informationObjectId = null;
          $digitalObject->delete();
        }

        $item->delete();
      }
    }

    // Remove METS file and AIP directory on uploads
    $dirPath = sfConfig::get('sf_web_dir').
      DIRECTORY_SEPARATOR.'uploads'.
      DIRECTORY_SEPARATOR.'aips'.
      DIRECTORY_SEPARATOR.$aip->uuid.
      DIRECTORY_SEPARATOR;

    $this->logSection('INFO', 'Deleting METS file and AIP folder ...');

    unlink($dirPath . 'METS.xml');
    rmdir($dirPath);

    // Delete AIP (removes QubitTerm::AIP_RELATION_ID relations
    // without updating related objects in ES)
    $this->logSection('INFO', 'Deleting AIP and relations ...');
    $aip->delete();

    // Update related IOs in ES
    $this->logSection('INFO', 'Updating related objects ...');
    $searchInstance = QubitSearch::getInstance();
    foreach (array_unique($relatedObjectsIds) as $objectId)
    {
      $node = new arElasticSearchInformationObjectPdo($objectId);
      $data = $node->serialize();

      $searchInstance->addDocument($data, 'QubitInformationObject');
    }

    $this->logSection('END', 'AIP deleted!');
  }
}

<?php
/**
 * Copyright (c) Enalean SAS, 2016. All Rights Reserved.
 * Copyright (c) Enalean, 2016. All Rights Reserved.
 *
 * This file is a part of Tuleap.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'autoload.php';
require_once 'constants.php';

use Tuleap\ReferenceAliasSVN\Dao;
use Tuleap\ReferenceAliasSVN\ReferencesImporter;
use Tuleap\ReferenceAliasSVN\ReferencesBuilder;
use Tuleap\Svn\Repository\RepositoryManager;
use Tuleap\Svn\Dao as SVNPluginDao;
use Tuleap\Svn\XMLRepositoryImporter;

class referencealias_svnPlugin extends Plugin
{
    public function __construct($id)
    {
        parent::__construct($id);
        $this->setScope(self::SCOPE_SYSTEM);
        $this->addHook(Event::IMPORT_COMPAT_REF_XML);
        $this->addHook(Event::GET_REFERENCE);
        $this->addHook(Event::GET_PLUGINS_EXTRA_REFERENCES);
    }

    /**
     * @see Plugin::getDependencies()
     */
    public function getDependencies()
    {
        return array('svn');
    }

    /**
     * @return Tuleap\ReferenceAliasSVN\Plugin\PluginInfo
     */
    public function getPluginInfo()
    {
        if (! $this->pluginInfo) {
            $this->pluginInfo = new Tuleap\ReferenceAliasSVN\Plugin\PluginInfo($this);
        }
        return $this->pluginInfo;
    }

    /** @see Event::IMPORT_COMPAT_REF_XML */
    public function import_compat_ref_xml($params)
    {
        if ($params['service_name'] === XMLRepositoryImporter::SERVICE_NAME) {
            $repository = $params['created_refs']['repository'];
            $logger     = new WrapperLogger($params['logger'], 'ReferenceAliasSVNImporter');
            $importer   = new ReferencesImporter($this->getCompatDao(), $logger);

            $importer->importCompatRefXML($params['project'], $params['xml_content'], $repository);
        }
    }

    /** @see Event::GET_REFERENCE */
    public function get_reference($params)
    {
        $reference = $this->getReferencesBuilder()->getReference($params['keyword'], $params['value']);

        if (! empty($reference)) {
            $params['reference'] = $reference;
        }
    }

    /** @see Event::GET_PLUGINS_EXTRA_REFERENCES */
    public function get_plugins_extra_references($params)
    {
        foreach ($this->getReferencesBuilder()->getExtraReferenceSpecs() as $refspec) {
            $params['refs'][] = $refspec;
        }
    }

    private function getReferencesBuilder()
    {
        $project_manager = ProjectManager::instance();

        return new ReferencesBuilder(
            $this->getCompatDao(),
            $project_manager,
            new RepositoryManager($this->getSVNDao(), $project_manager)
        );
    }

    private function getSVNDao()
    {
        return new SVNPluginDao();
    }

    private function getCompatDao()
    {
        return new Dao();
    }
}

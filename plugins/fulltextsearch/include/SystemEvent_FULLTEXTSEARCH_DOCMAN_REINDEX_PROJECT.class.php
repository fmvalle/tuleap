<?php
/**
 * Copyright (c) Enalean, 2014. All Rights Reserved.
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

class SystemEvent_FULLTEXTSEARCH_DOCMAN_REINDEX_PROJECT extends SystemEvent {

    const NAME = 'FULLTEXTSEARCH_DOCMAN_REINDEX_PROJECT';

    /**
     * @var FullTextSearchDocmanActions
     */
    protected $actions;

    /**
     * @var FullTextSearch_DocmanSystemEventManager
     */
    protected $system_event_manager;

    /**
     * @var TruncateLevelLogger
     */
    protected $logger;

    public function injectDependencies(
            FullTextSearchDocmanActions $actions,
            FullTextSearch_DocmanSystemEventManager $system_event_manager,
            TruncateLevelLogger $logger
        ) {
        $this->actions = $actions;
        $this->system_event_manager = $system_event_manager;
        $this->logger               = $logger;
    }

    /**
     * @return string a human readable representation of parameters
     */
    public function verbalizeParameters($with_link) {
        $group_id = (int)$this->getRequiredParameter(0);
        return 'project: '. $this->verbalizeProjectId($group_id, $with_link);
    }

    /**
     * Process the system event
     *
     * @return bool
     */
    public function process() {
        try {
            $project_id = (int) $this->getRequiredParameter(0);

            if ($this->system_event_manager->isProjectReindexationAlreadyQueued($project_id)) {
                $this->done('Skipped duplicate event');
                $this->logger->debug("Skipped duplicate event for project $project_id : ".self::NAME);
                return true;
            }

            $item_factory = Docman_ItemFactory::instance($project_id);
            $items        = new Docman_ProjectItemsBatchIterator($item_factory, $project_id);

            $notindexed_collector = new FullTextSearch_NotIndexedCollector();
            $this->actions->reIndexProjectDocuments($items, $project_id, $notindexed_collector);

            if (! $notindexed_collector->isAtLeastOneIndexed()) {
                $this->error('Nothing has been indexed. See syslog for details.');
                return false;
            } else if ($notindexed_collector->isAtLeastOneNotIndexed()) {
                $this->warning('Some items were not indexed: ['. implode(', ', $notindexed_collector->getIds()) .']. See syslog for details.');
            } else {
                $this->done();
            }
            return true;
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }

        return false;
    }
}

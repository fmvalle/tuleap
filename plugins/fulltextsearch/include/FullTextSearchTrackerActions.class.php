<?php
/**
 * Copyright (c) STMicroelectronics, 2012. All Rights Reserved.
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

/**
 * Class responsible to send indexation requests for tracker changesets to an indexation server
 */
class FullTextSearchTrackerActions {

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ElasticSearch_1_2_RequestTrackerDataFactory
     */
    private $tracker_data_factory;

    /**
     * @var FullTextSearch_IIndexDocuments
     */
    private $client;

    /** Constructor
     *
     * @param FullTextSearch_IIndexDocuments $client Search client
     *
     * @return Void
     */
    public function __construct(FullTextSearch_IIndexDocuments $client, ElasticSearch_1_2_RequestTrackerDataFactory $tracker_data_factory, Logger $logger) {
        $this->client               = $client;
        $this->tracker_data_factory = $tracker_data_factory;
        $this->logger               = $logger;
    }

    /**
     * Index a new followup comment
     *
     * @param Integer $groupId     Id of the project
     * @param Integer $artifactId  Id of the artifact
     * @param Integer $changesetId Id of the changeset
     * @param String  $text        Body of the followup comment
     *
     * @return Void
     */
    public function indexNewFollowUp(Tracker_Artifact $artifact) {
        $this->initializeMapping($artifact->getTracker());
        $this->logger->debug('[Tracker] Elasticsearch index artifact #' . $artifact->getId() . ' in tracker #' . $artifact->getTrackerId());
        $this->client->index(
            $artifact->getTrackerId(),
            $artifact->getId(),
            $this->tracker_data_factory->getFormattedArtifact($artifact)
        );
    }

    private function initializeMapping(Tracker $tracker) {
        if (! $this->mappingExists($tracker)) {
            $this->logger->debug('[Tracker] Elasticsearch set mapping for tracker #'.$tracker->getId());
            $this->client->setMapping((string) $tracker->getId(), $this->tracker_data_factory->getTrackerMapping($tracker));
        }
    }

    private function mappingExists(Tracker $tracker) {
        return count($this->client->getMapping((string) $tracker->getId())) > 0;
    }

    /**
     * Index an updated followup comment
     *
     * @param Integer $groupId     Id of the project
     * @param Integer $artifactId  Id of the artifact
     * @param Integer $changesetId Id of the changeset
     * @param String  $text        Body of the followup comment
     *
     * @return Void
     */
    public function indexFollowUpUpdate($groupId, $artifactId, $changesetId, $text) {
        $updateData = $this->client->initializeSetterData();
        $updateData = $this->client->appendSetterData($updateData, 'followup', $text);
        $this->client->update(fulltextsearchPlugin::SEARCH_TRACKER_TYPE, $changesetId, $updateData);
    }
}

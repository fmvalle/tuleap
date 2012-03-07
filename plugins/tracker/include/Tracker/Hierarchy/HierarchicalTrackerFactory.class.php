<?php
/**
 * Copyright (c) Enalean, 2012. All Rights Reserved.
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

class Tracker_Hierarchy_HierarchicalTrackerFactory {

    public function __construct(TrackerFactory $tracker_factory, Tracker_Hierarchy_Dao $dao) {
        $this->tracker_factory = $tracker_factory;
        $this->dao             = $dao;
    }
    
    /**
     * @return Tracker_Hierarchy_HierarchicalTracker
     */
    public function getWithChildren(Tracker $tracker) {
        $dar      = $this->dao->getChildren($tracker->getId());
        $children = array();
        
        foreach($dar as $row) {
            $children[] = $this->tracker_factory->getTrackerById($row['child_id']);
        }
        
        return new Tracker_Hierarchy_HierarchicalTracker($tracker, $children);
    }
    
    /**
     * @return Array of Tracker
     */
    public function getPossibleChildren(Tracker_Hierarchy_HierarchicalTracker $tracker) {
        $project_id        = $tracker->getProject()->getId();
        $project_trackers  = $this->tracker_factory->getTrackersByGroupId($project_id);
        
        unset($project_trackers[$tracker->getId()]);
        
        return $project_trackers;
    }
    
    
    public function getHierarchy(Tracker $tracker) {
        $project_trackers = $this->tracker_factory->getTrackersByGroupId($tracker->getGroupId());
        $hierarchy_dar    = $this->dao->getHierarchy($tracker->getGroupId());
        
        return $this->buildHierarchyChildrenOf(null, $hierarchy_dar, $project_trackers);
    }

    private function buildHierarchyChildrenOf($parent_id, $hierarchy_dar, $project_trackers) {
        $children = array();
        
        foreach($hierarchy_dar as $row) {
            if ($row['parent_id'] == $parent_id) {
                $id      = $row['child_id'];
                $tracker = $project_trackers[$id];
                
                $children[] = array('name'     => $tracker->getName(),
                                    'children' => $this->buildHierarchyChildrenOf($id, $hierarchy_dar, $project_trackers));
            }
        }
        
        return $children;
    }
}

?>

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

require_once 'Planning.class.php';
require_once 'PlanningArtifact.class.php';

/**
 * This visitor injects various artifact related data in a TreeNode to be used in mustache
 */
class Planning_ArtifactTreeNodeVisitor {
    
    /**
     * @var Planning
     */
    private $planning;
    
    /**
     * @var string the css class name
     */
    private $classname;
    
    /**
     * @var Tracker_ArtifactFactory
     */
    private $artifact_factory;
    
    /**
     * @var Tracker_Hierarchy_HierarchicalTrackerFactory
     */
    private $hierarchy_factory;
    
    public function __construct(Planning                                     $planning,
                                Tracker_ArtifactFactory                      $artifact_factory,
                                Tracker_Hierarchy_HierarchicalTrackerFactory $hierarchy_factory,
                                                                             $classname) {
        $this->planning          = $planning;
        $this->artifact_factory  = $artifact_factory;
        $this->classname         = $classname;
        $this->hierarchy_factory = $hierarchy_factory;
    }
    
    /**
     * @param string $classname The css classname to inject in TreeNode
     *
     * @return Planning_ArtifactTreeNodeVisitor
     */
    public static function build(Planning $planning, $classname) {
        $artifact_factory  = Tracker_ArtifactFactory::instance();
        $hierarchy_factory = Tracker_Hierarchy_HierarchicalTrackerFactory::instance();
        return new Planning_ArtifactTreeNodeVisitor($planning, $artifact_factory, $hierarchy_factory, $classname);
    }
    
    private function injectArtifactInChildren(TreeNode $node) {
        foreach ($node->getChildren() as $child) {
            $child->accept($this);
        }
    }

    public function visit(TreeNode $node) {
        $row      = $node->getData();
        $artifact = $this->artifact_factory->getArtifactById($row['id']);
        
        if ($artifact) {
            $planning_artifact = new PlanningArtifact($artifact, $this->planning);
            
            $node->setObject($planning_artifact);
            $this->buildNodeData($node, $row, $planning_artifact);
        }
        $this->injectArtifactInChildren($node);
    }
    
    private function buildNodeData(TreeNode $node, $row, PlanningArtifact $planning_artifact) {
        $row['artifact_id']          = $planning_artifact->getId();
        $row['title']                = $planning_artifact->getTitle();
        $row['class']                = $this->classname;
        $row['uri']                  = $planning_artifact->getEditUri();
        $row['xref']                 = $planning_artifact->getXRef();
        $row['editLabel']            = $GLOBALS['Language']->getText('plugin_agiledashboard', 'edit_item');
        $row['planningDraggable']    = $this->getPlanningDraggableClass($planning_artifact);
        
        if (!isset($row['allowedChildrenTypes'])) {
            $row['allowedChildrenTypes'] = $this->hierarchy_factory->getChildren($planning_artifact->getTracker());
        }
        
        $node->setData($row);
    }
    
    private function getPlanningDraggableClass(PlanningArtifact $planning_artifact) {
        if ($planning_artifact->isPlannifiable()) {
            return 'planning-draggable';
        }
    }
}

?>

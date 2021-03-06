<?php
/**
 * Copyright (c) Enalean, 2012 - 2016. All Rights Reserved.
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

use Tuleap\Tracker\Artifact\ChangesetValueComputed;
use Tuleap\Tracker\DAO\ComputedDao;
use Tuleap\Tracker\Deprecation\DeprecationRetriever;
use Tuleap\Tracker\Deprecation\Dao;
use Tuleap\Tracker\REST\Artifact\ArtifactFieldComputedValueFullRepresentation;

class Tracker_FormElement_Field_Computed extends Tracker_FormElement_Field_Float
{
    const FIELD_VALUE_IS_AUTOCOMPUTED = 'is_autocomputed';
    const FIELD_VALUE_MANUAL          = 'manual_value';

    public $default_properties = array(
        'target_field_name' => array(
            'value' => null,
            'type'  => 'string',
            'size'  => 40,
        ),
        'fast_compute' => array(
            'value' => null,
            'type'  => 'upgrade_button',
        ),
    );

    public function __construct(
        $id,
        $tracker_id,
        $parent_id,
        $name,
        $label,
        $description,
        $use_it,
        $scope,
        $required,
        $notifications,
        $rank,
        Tracker_FormElement $original_field = null
    ) {
        parent::__construct(
            $id,
            $tracker_id,
            $parent_id,
            $name,
            $label,
            $description,
            $use_it,
            $scope,
            $required,
            $notifications,
            $rank,
            $original_field
        );

        $this->doNotDisplaySpecialPropertiesAtFieldCreation();
    }

    private function doNotDisplaySpecialPropertiesAtFieldCreation()
    {
        $this->clearFastCompute();
        $this->clearTargetFieldName();
        $this->clearCache();
    }

    private function clearFastCompute()
    {
        if ($this->getProperty('fast_compute') === null) {
            unset($this->default_properties['fast_compute']);
        }
    }

    private function clearTargetFieldName()
    {
        if ($this->getProperty('target_field_name') === null) {
            unset($this->default_properties['target_field_name']);
        }
    }

    private function clearCache()
    {
        $this->cache_specific_properties = null;
    }

    public function isCSVImportable()
    {
        return false;
    }

    public function canBeUsedForLegacyAutocomputeCalculation()
    {
        return false;
    }

    public function useFastCompute() {
        return $this->getProperty('fast_compute') == 1;
    }

    /**
     * Given an artifact, return a numerical value of the field for this artifact.
     *
     * @param PFUser             $user                  The user who see the results
     * @param Tracker_Artifact $artifact              The artifact on which the value is computed
     * @param Array            $computed_artifact_ids Hash map to store artifacts already computed (avoid cycles)
     *
     * @return float|null if there are no data (/!\ it's on purpose, otherwise we can mean to distinguish if there is data but 0 vs no data at all, for the graph plot)
     */
    public function getComputedValue(PFUser $user, Tracker_Artifact $artifact, $timestamp = null, array &$computed_artifact_ids = array()) {
        if ($this->useFastCompute()) {
            return $this->getFastComputedValue($artifact->getId(), $timestamp, true);
        }
        $computed_artifact_ids[$artifact->getId()] = true;

        if ($timestamp === null) {
            $dar = $this->getDao()->getFieldValues(array($artifact->getId()), $this->getProperty('target_field_name'));
        } else {
            $dar = Tracker_FormElement_Field_ComputedDaoCache::instance()->getFieldValuesAtTimestamp($artifact->getId(), $this->getProperty('target_field_name'), $timestamp, $this->getId());
        }
        return $this->computeValuesVersion($dar, $user, $timestamp, $computed_artifact_ids);
    }

    private function getComputedValueWithNoStopOnManualValue(Tracker_Artifact $artifact)
    {
        return $this->getFastComputedValue($artifact->getId(), null, true);
    }

    protected function getNoValueLabel()
    {
        return "<span class='empty_value auto-computed-label'>".$GLOBALS['Language']->getText('plugin_tracker_formelement_exception', 'no_value_for_field')."</span>";
    }

    protected function getComputedValueWithNoLabel(Tracker_Artifact $artifact, PFUser $user, $stop_on_manual_value)
    {
        if ($stop_on_manual_value || $this->getDeprecationRetriever()->isALegacyField($this)) {
            $computed_value = $this->getComputedValue($user, $artifact);
        } else {
            $computed_value = $this->getComputedValueWithNoStopOnManualValue($artifact);
        }

        return ($computed_value !== null) ? $computed_value : $GLOBALS['Language']->getText('plugin_tracker_formelement_exception', 'no_value_for_field');
    }

    protected function processUpdate(
        Tracker_IDisplayTrackerLayout $layout,
        $request,
        $current_user,
        $redirect = false
    ) {
        $formElement_data = $request->get('formElement_data');

        if ($formElement_data !== false) {
            $default_specific_properties = array(
                'fast_compute'      => '1',
                'target_field_name' => $formElement_data['name']
            );
            $submitted_specific_properties = isset($formElement_data['specific_properties']) ? $formElement_data['specific_properties'] : array();

            $merged_specific_properties = array_merge(
                $default_specific_properties,
                $submitted_specific_properties
            );

            $formElement_data['specific_properties'] = $merged_specific_properties;
            $request->set('formElement_data', $formElement_data);

            $GLOBALS['Response']->addFeedback(
                'warning',
                $GLOBALS['Language']->getText('plugin_tracker_deprecation_field', 'warning_permissions', $this->getName())
            );
        }

        parent::processUpdate(
            $layout,
            $request,
            $current_user,
            $redirect
        );
    }

    public function afterCreate($formElement_data = array())
    {
        $formElement_data['specific_properties']['fast_compute']      = '1';
        $formElement_data['specific_properties']['target_field_name'] = $this->name;
        $this->storeProperties($formElement_data['specific_properties']);

        parent::afterCreate($formElement_data);
    }

    protected function getFastComputedValue($artifact_id, $timestamp = null, $stop_on_manual_value)
    {
        $sum                   = null;
        $target_field_name     = $this->getName();
        $artifact_ids_to_fetch = array($artifact_id);
        $already_seen          = array();

        do {
            if ($timestamp !== null) {
                $dar = $this->getDao()->getFieldValuesAtTimestamp($artifact_ids_to_fetch, $target_field_name, $timestamp, $this->getId());
            } else {
                $dar = $this->getDao()->getComputedFieldValues($artifact_ids_to_fetch, $target_field_name, $this->getId(), $stop_on_manual_value);
            }

            $current_fetch_artifact = $artifact_ids_to_fetch;
            $artifact_ids_to_fetch  = array();
            $last_id                = null;
            if ($dar) {
                foreach ($dar as $row) {
                    if (! isset($already_seen[$row['id']]) && $last_id != $row['parent_id']) {
                        if (isset($row['value']) && $row['value'] !== null) {
                            $already_seen[$row['parent_id']] = true;
                            $last_id                         = $row['parent_id'];
                            $sum                            += $row['value'];
                        } elseif ($row['type'] == 'computed') {
                            $artifact_ids_to_fetch[]  = $row['id'];
                        } elseif (isset($row[$row['type'].'_value'])) {
                            $already_seen[$row['id']] = true;
                            $sum                     += $row[$row['type'].'_value'];
                        }
                    }
                }
                $dar->freeMemory();
            }

            foreach ($current_fetch_artifact as $artifact_fetched) {
                $already_seen[$artifact_fetched] = true;
            }
        } while (count($artifact_ids_to_fetch) > 0);

        return $sum;
    }

    private function computeValuesVersion(DataAccessResult $dar, PFUser $user, $timestamp, array &$computed_artifact_ids) {
        $sum = null;

        foreach ($dar as $row) {
            if (! isset($computed_artifact_ids[$row['id']])) {
                $linked_artifact = Tracker_ArtifactFactory::instance()->getInstanceFromRow($row);
                if ($linked_artifact->userCanView($user)) {
                    $this->addIfNotNull($sum, $this->getValueOrContinueComputing($user, $linked_artifact, $row, $timestamp, $computed_artifact_ids));
                }
            }
        }

        return $sum;
    }

    private function addIfNotNull(&$sum, $value) {
        if ($value !== null) {
            $sum += $value;
        }
    }

    private function getValueOrContinueComputing(PFUser $user, Tracker_Artifact $linked_artifact, array $row, $timestamp, array &$computed_artifact_ids) {
        if ($row['type'] == 'computed') {
            return $this->getFieldValue($user, $linked_artifact, $timestamp, $computed_artifact_ids);
        } else if (! isset($row[$row['type'].'_value'])) {
            $computed_artifact_ids[$row['id']] = true;
            return null;
        } else {
            $computed_artifact_ids[$row['id']] = true;
            return $row[$row['type'].'_value'];
        }
    }

    private function getFieldValue(PFUser $user, Tracker_Artifact $artifact, $timestamp, array &$computed_artifact_ids) {
        $field = $this->getTargetField($user, $artifact);
        if ($field) {
            return $field->getComputedValue($user, $artifact, $timestamp, $computed_artifact_ids);
        }
        return null;
    }

    private function getTargetField(PFUser $user, Tracker_Artifact $artifact) {
        return $this->getFormElementFactory()->getComputableFieldByNameForUser(
            $artifact->getTracker()->getId(),
            $this->getProperty('target_field_name'),
            $user
        );
    }

    public function validateValue($value)
    {
        if (! is_array($value)) {
            return false;
        }

        if (! isset($value[self::FIELD_VALUE_MANUAL]) && ! isset($value[self::FIELD_VALUE_IS_AUTOCOMPUTED])) {
            return false;
        }

        if (isset($value[self::FIELD_VALUE_MANUAL]) && isset($value[self::FIELD_VALUE_IS_AUTOCOMPUTED]) &&
                $value[self::FIELD_VALUE_IS_AUTOCOMPUTED]) {
            return $value[self::FIELD_VALUE_MANUAL] === '';
        }

        if (isset($value[self::FIELD_VALUE_MANUAL])) {
            $is_a_float = preg_match('/^'. $this->pattern .'$/', $value[self::FIELD_VALUE_MANUAL]) === 1;
            if (! $is_a_float) {
                $GLOBALS['Response']->addFeedback('error', $this->getValidatorErrorMessage());
            }
            return $is_a_float;
        }

        return true;
    }

    public function fetchArtifactValueWithEditionFormIfEditable(
        Tracker_Artifact $artifact,
        Tracker_Artifact_ChangesetValue $value = null,
        $submitted_values = array()
    ) {
        return $this->fetchArtifactValueReadOnly($artifact, $value).
            $this->getHiddenArtifactValueForEdition($artifact, $value, $submitted_values);
    }

    protected function getHiddenArtifactValueForEdition(
        Tracker_Artifact $artifact,
        Tracker_Artifact_ChangesetValue $value = null,
        $submitted_values = array()
    ) {
        if ($this->getDeprecationRetriever()->isALegacyField($this)) {
            return '';
        }

        $purifier       = Codendi_HTMLPurifier::instance();
        $current_user   = UserManager::instance()->getCurrentUser();
        $computed_value = $this->getComputedValueWithNoLabel($artifact, $current_user, false);

        $html  = '<div class="tracker_hidden_edition_field" data-field-id="' . $purifier->purify($this->getId()) . '">
                    <div class="input-append">';
        $html .= $this->fetchArtifactValue($artifact, $value, $submitted_values);
        $html .= $this->fetchBackToAutocomputedButton(false);
        $html .= $this->fetchComputedValueWithLabel($computed_value);
        $html .= '</div></div>';

        return $html;
    }

    private function fetchBackToAutocomputedButton($is_disabled)
    {
        $disabled = '';
        if ($is_disabled) {
            $disabled = 'disabled="disabled"';
        }
        $html  = '<a class="btn auto-compute" ' . $disabled . '><i class="icon-repeat icon-flip-horizontal"></i>';
        $html .= $GLOBALS['Language']->getText('plugin_tracker_deprecation_field', 'title_autocompute');
        $html .= '</a>';

        return $html;
    }

    private function fetchComputedValueWithLabel($computed_value)
    {
        $purifier = Codendi_HTMLPurifier::instance();

        $html  = '<span class="original-value">';
        $html .= $GLOBALS['Language']->getText('plugin_tracker_deprecation_field', 'title_original_value');
        $html .= $purifier->purify($computed_value) . '</span>';

        return $html;
    }

    protected function fetchArtifactValue(
        Tracker_Artifact $artifact,
        Tracker_Artifact_ChangesetValue $value = null,
        $submitted_values = array()
    ) {
        $displayed_value = null;
        if ($value !== null) {
            $displayed_value = $value->getValue();
        }
        $is_autocomputed = $displayed_value === null;

        if (isset($submitted_values[0][$this->getId()][self::FIELD_VALUE_MANUAL])) {
            $displayed_value = $submitted_values[0][$this->getId()][self::FIELD_VALUE_MANUAL];
        }

        return $this->fetchComputedInputs($displayed_value, $is_autocomputed);
    }

    private function fetchComputedInputs($displayed_value, $is_autocomputed)
    {
        $purifier = Codendi_HTMLPurifier::instance();
        $html     = '<input type="text"
            name="artifact[' . $purifier->purify($this->getId()) . '][' . self::FIELD_VALUE_MANUAL . ']"
            value="' . $purifier->purify($displayed_value) . '" />';
        $html    .= '<input type="hidden"
            name="artifact[' . $purifier->purify($this->getId()) . '][' . self::FIELD_VALUE_IS_AUTOCOMPUTED . ']"
            value="' . $purifier->purify($is_autocomputed) . '" />';

        return $html;
    }

    protected function saveValue($artifact, $changeset_value_id, $value, Tracker_Artifact_ChangesetValue $previous_changesetvalue = null)
    {
        return $this->getValueDao()->create($changeset_value_id, $value);
    }

    /**
     * Fetch the html code to display the field value in artifact in read only mode
     *
     * @param Tracker_Artifact                $artifact The artifact
     * @param Tracker_Artifact_ChangesetValue $value    The actual value of the field
     *
     * @return string
     */
    public function fetchArtifactValueReadOnly(Tracker_Artifact $artifact, Tracker_Artifact_ChangesetValue $value = null)
    {
        $purifier     = Codendi_HTMLPurifier::instance();
        $current_user = UserManager::instance()->getCurrentUser();

        if ($this->getDeprecationRetriever()->isALegacyField($this)) {
            $value = $this->getComputedValue($current_user, $artifact);
            return '<div class="auto-computed-label computed-legacy">'.  $purifier->purify($value) . '</div>';
        }

        $computed_value = $this->getComputedValueWithNoStopOnManualValue($artifact);

        if ($value !== null) {
            $value = $value->getValue();
        }

        if (! $computed_value) {
            $computed_value = $GLOBALS['Language']->getText('plugin_tracker_formelement_exception', 'no_value_for_field');
        }
        $html_computed_value = '<span class="auto-computed">'. $purifier->purify($computed_value) .' (' .
            $GLOBALS['Language']->getText('plugin_tracker', 'autocompute_field').')</span>';

        if ($value === null) {
            $value = $html_computed_value;
        }

        return $this->fetchFieldReadOnly($value, $html_computed_value);
    }

    private function fetchFieldReadOnly($value, $html_computed_value) {
        return '<div class="auto-computed-label">'. $value. '</div>'.
            '<div class="back-to-autocompute">'.$html_computed_value.'</div>';
    }

    /**
     * Fetch data to display the field value in mail
     *
     * @param Tracker_Artifact                $artifact         The artifact
     * @param PFUser                          $user             The user who will receive the email
     * @param Tracker_Artifact_ChangesetValue $value            The actual value of the field
     * @param string                          $format           output format
     *
     * @return string
     */
    public function fetchMailArtifactValue(
        Tracker_Artifact $artifact,
        PFUser $user,
        Tracker_Artifact_ChangesetValue $value = null,
        $format = 'text'
    ) {
        $current_user = UserManager::instance()->getCurrentUser();
        $changeset    = $artifact->getLastChangesetWithFieldValue($this);
        $value        = $this->getValueForChangeset($changeset, $current_user);

        return ($value !== null) ? $value : "-";
    }

    /**
     * Fetch the html code to display the field value in tooltip
     *
     * @param Tracker_Artifact $artifact
     * @param Tracker_Artifact_ChangesetValue_Integer $value The changeset value of this field
     * @return string The html code to display the field value in tooltip
     */
    protected function fetchTooltipValue(Tracker_Artifact $artifact, Tracker_Artifact_ChangesetValue $value = null) {
        $current_user = UserManager::instance()->getCurrentUser();
        $changeset    = $artifact->getLastChangesetWithFieldValue($this);
        $value        = $this->getValueForChangeset($changeset, $current_user);

        return ($value !== null) ? $value : "-";
    }

    public function fetchChangesetValue($artifact_id, $changeset_id, $value, $report = null, $from_aid = null)
    {
        $current_user = UserManager::instance()->getCurrentUser();
        $artifact     = Tracker_ArtifactFactory::instance()->getArtifactById($artifact_id);

        $changeset = $this->getTrackerChangesetFactory()->getChangeset($artifact, $changeset_id);
        return $this->getValueForChangeset($changeset, $current_user);
    }

    public function getSoapValue(PFUser $user, Tracker_Artifact_Changeset $changeset)
    {
        if ($this->userCanRead($user)) {
            $artifact     = $changeset->getArtifact();
            $manual_value = $this->getComputedValueWithNoLabel($artifact, $user, false);

            return array(
                'field_name'  => $this->getName(),
                'field_label' => $this->getLabel(),
                'field_value' => array('value' => (string) $manual_value)
            );
        }
        return null;
    }

    public function getRESTValue(PFUser $user, Tracker_Artifact_Changeset $changeset) {
        return $this->getFullRESTValue($user, $changeset);
    }

    public function getFullRESTValue(PFUser $user, Tracker_Artifact_Changeset $changeset)
    {
        $manual_value                             = $this->getManualValueForChangeset($changeset);
        $artifact_field_value_full_representation = new ArtifactFieldComputedValueFullRepresentation();
        $artifact_field_value_full_representation->build(
            $this->getId(),
            Tracker_FormElementFactory::instance()->getType($this),
            $this->getLabel(),
            $manual_value === null,
            $this->getComputedValueWithNoStopOnManualValue($changeset->getArtifact()),
            $this->getManualValueForChangeset($changeset)
        );
        return $artifact_field_value_full_representation;
    }

    private function getValueForChangeset(Tracker_Artifact_Changeset $artifact_changeset, PFUser $user)
    {
        $changeset = $artifact_changeset->getValue($this);
        if ($changeset && $changeset->getNumeric() !== null) {
            return $changeset->getNumeric();
        } else {
            return $this->getComputedValue($user, $artifact_changeset->getArtifact());
        }
    }

    /**
     * @return int|float|null
     */
    private function getManualValueForChangeset(Tracker_Artifact_Changeset $artifact_changeset)
    {
        $changeset_value = $artifact_changeset->getValue($this);
        if ($changeset_value && $changeset_value->getNumeric()) {
            return $changeset_value->getNumeric();
        }

        return null;
    }

    public function getFieldDataFromRESTValue(array $value, Tracker_Artifact $artifact = null) {
        if ($this->isAutocomputedDisabledAndNoManualValueProvided($value) || isset($value['value'])) {
            throw new Tracker_FormElement_InvalidFieldValueException(
                'Expected format for a computed field ' .
                ' : {"field_id" : 15458, "manual_value" : 12} or {"field_id" : 15458, "is_autocomputed" : true}'
            );
        }

        return $this->getRestFieldData($value);
    }

    /**
     * @return bool
     */
    private function isAutocomputedDisabledAndNoManualValueProvided(array $value)
    {
        return isset($value[self::FIELD_VALUE_IS_AUTOCOMPUTED]) && $value[self::FIELD_VALUE_IS_AUTOCOMPUTED] === false
            && (! isset($value[self::FIELD_VALUE_MANUAL]) || $value[self::FIELD_VALUE_MANUAL] === null);
    }

    /**
     * Display the html field in the admin ui
     * @return string html
     */
    public function fetchAdminFormElement()
    {
        $html  = '<div class="input-append">';
        $html .= $this->fetchComputedInputs('', true);
        $html .= $this->fetchBackToAutocomputedButton(true);
        $html .= $this->fetchComputedValueWithLabel(
            $GLOBALS['Language']->getText('plugin_tracker_formelement_exception', 'no_value_for_field')
        );
        $html .= "</div>";

        return $html;
    }

    /**
     * @return the label of the field (mainly used in admin part)
     */
    public static function getFactoryLabel() {
        return $GLOBALS['Language']->getText('plugin_tracker_formelement_admin', 'aggregate_label');
    }

    /**
     * @return the description of the field (mainly used in admin part)
     */
    public static function getFactoryDescription() {
        return $GLOBALS['Language']->getText('plugin_tracker_formelement_admin', 'aggregate_description');
    }

    /**
     * @return the path to the icon
     */
    public static function getFactoryIconUseIt() {
        return $GLOBALS['HTML']->getImagePath('ic/sum.png');
    }

    /**
     * @return the path to the icon
     */
    public static function getFactoryIconCreate() {
        return $GLOBALS['HTML']->getImagePath('ic/sum.png');
    }

    protected function getDao() {
        return new Tracker_FormElement_Field_ComputedDao();
    }

    public function getCriteriaFrom($criteria) {
    }

    public function getCriteriaWhere($criteria) {
    }

    public function getQuerySelect() {
    }

    public function getQueryFrom() {
    }

    public function fetchCriteriaValue($criteria) {
    }

    public function fetchRawValue($value) {
    }

    protected function getCriteriaDao() {
    }

    /**
     * Fetch the element for the submit new artifact form
     *
     * @return string html
     */
    public function fetchSubmit($submitted_values = array())
    {
        $purifier = Codendi_HTMLPurifier::instance();
        $required = $this->required ? ' <span class="highlight">*</span>' : '';

        $html = '<div>';
        $html .= '<div class="tracker_artifact_field tracker_artifact_field-computed editable">';

        if ($this->userCanRead()) {
            if ($this->userCanUpdate()) {
                $title = $purifier->purify($GLOBALS['Language']->getText('plugin_tracker_artifact', 'edit_field', array($this->getLabel())));
                $html .= '<button type="button" title="' . $title . '" class="tracker_formelement_edit">' . $purifier->purify($this->getLabel())  . $required . '</button>';
                $html .= '<label for="tracker_artifact_'. $this->id .'" title="'. $purifier->purify($this->description) .
                    '" class="tracker_formelement_label">'. $purifier->purify($this->getLabel())  . $required .'</label>';
            }
        }
        $html .= '<span class="auto-computed">'. $this->getNoValueLabel() .' (' .
            $GLOBALS['Language']->getText('plugin_tracker', 'autocompute_field').')</span>';

        $html .= '<div class="input-append add-field" data-field-id="'. $this->getId() .'">';
        $html .= $this->fetchComputedInputs('', true);
        $html .= $this->fetchBackToAutocomputedButton(false);
        $html .= $this->fetchComputedValueWithLabel(
            $GLOBALS['Language']->getText('plugin_tracker_formelement_exception', 'no_value_for_field')
        );

        $html .= '</div></div></div>';

        return $html;
    }

    public function fetchSubmitMasschange()
    {
        $purifier = Codendi_HTMLPurifier::instance();
        $html     = '';
        if ($this->userCanUpdate()) {
            $required = $this->isRequired() ? ' <span class="highlight">*</span>' : '';
            $html    .= '<div class="field-masschange tracker_artifact_field tracker_artifact_field-computed editable"
                         data-field-id="'. $purifier->purify($this->getId()) . '">';

            $html    .= '<div class="edition-mass-change">';
            $html    .= '<label for="tracker_artifact_' . $purifier->purify($this->getId()) . '"
                        title="' . $purifier->purify($this->description) . '"  class="tracker_formelement_label">' .
                        $purifier->purify($this->getLabel()) . $required . '</label>';
            $html    .= '<div class=" input-append">';
            $html    .= $this->fetchSubmitValueMasschange();
            $html    .= '</div>';
            $html    .= '</div>';

            $html    .= '<div class="display-mass-change display-mass-change-hidden">';
            $html    .= '<button class="tracker_formelement_edit edit-mass-change-autocompute" type="button">' .
                        $purifier->purify($this->getLabel()) . $required . '</button>';
            $html    .= '<span class="auto-computed">';
            $html    .= $purifier->purify(ucfirst($GLOBALS['Language']->getText('plugin_tracker', 'autocompute_field')));
            $html    .= '</span>';
            $html    .= '</div>';

            $html    .= '</div>';
        }
        return $html;
    }

    protected function fetchSubmitValueMasschange()
    {
        $unchanged = $GLOBALS['Language']->getText('global','unchanged');
        $html      = $this->fetchComputedInputs($unchanged, false);
        $html     .= $this->fetchBackToAutocomputedButton(false);
        return $html;
    }

    protected function getValueDao() {
        return new ComputedDao();
    }

    public function fetchFollowUp($artifact, $from, $to) {
    }

    public function fetchRawValueFromChangeset($changeset) {
    }

    protected function keepValue($artifact, $changeset_value_id, Tracker_Artifact_ChangesetValue $previous_changesetvalue) {
        return $this->getValueDao()->keep($previous_changesetvalue->getId(), $changeset_value_id);
    }

    public function getChangesetValue($changeset, $value_id, $has_changed)
    {
        $changeset_value = null;
        if ($row = $this->getValueDao()->searchById($value_id, $this->id)->getRow()) {
            $int_row_value = $row['value'];
            if ($int_row_value !== null) {
                $int_row_value = $int_row_value;
            }
            $changeset_value = new ChangesetValueComputed($value_id, $this, $has_changed, $int_row_value);
        }
        return $changeset_value;
    }

    private function getDeprecationRetriever()
    {
        return new DeprecationRetriever(
            new Dao(),
            ProjectManager::instance(),
            TrackerFactory::instance(),
            Tracker_FormElementFactory::instance()
        );
    }

    private function getTrackerChangesetFactory()
    {
        $factory_builder = new Tracker_Artifact_ChangesetFactoryBuilder();
        return $factory_builder::build();
    }

    /**
     * Save the value submitted by the user in the new changeset
     *
     * @param Tracker_Artifact           $artifact         The artifact
     * @param Tracker_Artifact_Changeset $old_changeset    The old changeset. null if it is the first one
     * @param int                        $new_changeset_id The id of the new changeset
     * @param mixed                      $value  The value submitted by the user
     * @param boolean $is_submission true if artifact submission, false if artifact update
     *
     * @return bool true if success
     */
    public function saveNewChangeset(
        $artifact,
        $old_changeset,
        $new_changeset_id,
        $value,
        PFUser $submitter,
        $is_submission = false,
        $bypass_permissions = false
    ) {
        if ($this->getDeprecationRetriever()->isALegacyField($this) || ! $this->userCanUpdate($submitter)) {
            return true;
        }

        $new_value = '';

        if (isset($value[self::FIELD_VALUE_MANUAL])) {
            $new_value = $value[self::FIELD_VALUE_MANUAL];
        }

        return parent::saveNewChangeset(
            $artifact,
            $old_changeset,
            $new_changeset_id,
            $new_value,
            $submitter,
            $is_submission,
            $bypass_permissions
        );
    }

    /**
     * @see Tracker_FormElement_Field::hasChanges()
     */
    public function hasChanges(Tracker_Artifact $artifact, Tracker_Artifact_ChangesetValue $old_value, $new_value)
    {
        if ($old_value->getNumeric() === 0 && $new_value === '') {
            return true;
        }

        return $old_value->getNumeric() != $new_value;
    }

    public function getSoapAvailableValues() {
    }

    public function testImport() {
        return true;
    }

    protected function validate(Tracker_Artifact $artifact, $value)
    {
        return $this->validateValue($value);
    }

    public function accept(Tracker_FormElement_FieldVisitor $visitor) {
        return $visitor->visitComputed($this);
    }

    /**
     * @return int | null if no value found
     */
    public function getCachedValue(PFUser $user, Tracker_Artifact $artifact, $timestamp = null) {
        $dao   = Tracker_FormElement_Field_ComputedDaoCache::instance();
        $value = $dao->getCachedFieldValueAtTimestamp($artifact->getId(), $this->getId(), $timestamp);

        if ($value === false) {
            $value = $this->getComputedValue($user, $artifact, $timestamp);
            $dao->saveCachedFieldValueAtTimestamp($artifact->getId(), $this->getId(), $timestamp, $value);
        }

        return $value;
    }

    public function canBeUsedAsReportCriterion() {
        return false;
    }

    public function validateFieldWithPermissionsAndRequiredStatus(
        Tracker_Artifact $artifact,
        $submitted_value,
        Tracker_Artifact_ChangesetValue $last_changeset_value = null,
        $is_submission = null
    ) {
        $is_valid = true;
        $hasPermission = $this->userCanUpdate();
        if ($is_submission) {
            $hasPermission = $this->userCanSubmit();
        }
        if ($last_changeset_value === null && ( $this->isAnEmptyValue($submitted_value) || $this->isAnEmptyArray($submitted_value)) && $hasPermission && $this->isRequired()) {
            $is_valid = false;
            $this->setHasErrors(true);

            $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker_common_artifact', 'err_required', $this->getLabel(). ' ('. $this->getName() .')'));
        } else if ($hasPermission) {
            $is_valid = $this->isValidRegardingRequiredProperty($artifact, $submitted_value) && $this->validateField($artifact, $submitted_value);
        }
        return $is_valid;
    }

    private function isAnEmptyArray($value)
    {
        return is_array($value) && empty($value);
    }

    private function isAnEmptyValue($value)
    {
        return ! is_array($value) && $value === null;
    }
}

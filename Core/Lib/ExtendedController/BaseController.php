<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2018 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Core\Lib\ExtendedController;

use FacturaScripts\Core\Base;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Model\CodeModel;

/**
 * Description of BaseController
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
abstract class BaseController extends Base\Controller
{

    const MODEL_NAMESPACE = '\\FacturaScripts\\Dinamic\\Model\\';

    /**
     * Indicates the active view.
     *
     * @var string
     */
    public $active;

    /**
     * Model to use with select and autocomplete filters.
     *
     * @var CodeModel
     */
    public $codeModel;

    /**
     * Indicates current view, when drawing.
     *
     * @var string
     */
    private $current;

    /**
     * Object to export data.
     *
     * @var ExportManager
     */
    public $exportManager;

    /**
     * Tools to work with numbers.
     *
     * @var Base\NumberTools
     */
    public $numberTools;

    /**
     * List of views displayed by the controller.
     *
     * @var BaseView[]
     */
    public $views;

    /**
     * Inserts the views to display.
     */
    abstract protected function createViews();

    /**
     * Loads the data to display.
     *
     * @param string   $viewName
     * @param BaseView $view
     */
    abstract protected function loadData($viewName, $view);

    /**
     * Initializes all the objects and properties.
     *
     * @param Base\Cache      $cache
     * @param Base\Translator $i18n
     * @param Base\MiniLog    $miniLog
     * @param string          $className
     * @param string          $uri
     */
    public function __construct(&$cache, &$i18n, &$miniLog, $className, $uri = '')
    {
        parent::__construct($cache, $i18n, $miniLog, $className, $uri);
        $activeTabGet = $this->request->query->get('activetab', '');
        $this->active = $this->request->request->get('activetab', $activeTabGet);
        $this->codeModel = new CodeModel();
        $this->exportManager = new ExportManager();
        $this->numberTools = new Base\NumberTools();
        $this->views = [];
    }

    /**
     * Adds a new button to the tab.
     *
     * @param string $viewName
     * @param array  $btnArray
     */
    public function addButton($viewName, $btnArray)
    {
        $row = $this->views[$viewName]->getRow('actions');
        if ($row) {
            $row->addButton($btnArray);
        }
    }

    /**
     * 
     * @param string   $viewName
     * @param BaseView $view
     */
    public function addCustomView($viewName, $view)
    {
        if ($viewName !== $view->getViewName()) {
            $this->miniLog->error('$viewName must be equals to $view->name');
            return;
        }

        $view->loadPageOptions($this->user);
        $this->views[$viewName] = $view;
        if (empty($this->active)) {
            $this->active = $viewName;
        }
    }

    /**
     * 
     * @return BaseView
     */
    public function getCurrentView()
    {
        return $this->views[$this->current];
    }

    /**
     * Returns the configuration value for the indicated view.
     *
     * @param string $viewName
     * @param string $property
     *
     * @return mixed
     */
    public function getSettings($viewName, $property)
    {
        return isset($this->views[$viewName]->settings[$property]) ? $this->views[$viewName]->settings[$property] : null;
    }

    /**
     * 
     * @param string $viewName
     */
    public function setCurrentView($viewName)
    {
        $this->current = $viewName;
    }

    /**
     * Set value for setting of a view
     *
     * @param string $viewName
     * @param string $property
     * @param mixed  $value
     */
    public function setSettings($viewName, $property, $value)
    {
        $this->views[$viewName]->settings[$property] = $value;
    }

    /**
     * Run the autocomplete action.
     * Returns a JSON string for the searched values.
     *
     * @return array
     */
    protected function autocompleteAction(): array
    {
        $data = $this->requestGet(['field', 'source', 'fieldcode', 'fieldtitle', 'term', 'formname']);
        if ($data['source'] == '') {
            return $this->getAutocompleteValues($data['formname'], $data['field']);
        }

        $results = [];
        foreach ($this->codeModel->search($data['source'], $data['fieldcode'], $data['fieldtitle'], $data['term']) as $value) {
            $results[] = ['key' => $value->code, 'value' => $value->description];
        }
        return $results;
    }

    /**
     * Action to delete data.
     *
     * @return bool
     */
    protected function deleteAction()
    {
        if (!$this->permissions->allowDelete) {
            $this->miniLog->alert($this->i18n->trans('not-allowed-delete'));
            return false;
        }

        $model = $this->views[$this->active]->model;
        $codes = $this->request->request->get('code', '');

        // deleting multiples rows?
        if (is_array($codes)) {
            $numDeletes = 0;
            foreach ($codes as $cod) {
                if ($model->loadFromCode($cod) && $model->delete()) {
                    ++$numDeletes;
                } else {
                    break;
                }
            }

            if ($numDeletes > 0) {
                $this->miniLog->notice($this->i18n->trans('record-deleted-correctly'));
                return true;
            }
        } elseif ($model->loadFromCode($codes) && $model->delete()) {
            // deleting a single row?
            $this->miniLog->notice($this->i18n->trans('record-deleted-correctly'));
            return true;
        }

        $this->miniLog->warning($this->i18n->trans('record-deleted-error'));
        return false;
    }

    /**
     * 
     */
    protected function finalStep()
    {
        AssetManager::merge($this->assets, BaseView::getAssets());
    }

    /**
     * Return values from Widget Values for autocomplete action
     *
     * @param string $viewName
     * @param string $fieldName
     *
     * @return array
     */
    protected function getAutocompleteValues(string $viewName, string $fieldName): array
    {
        $result = [];
        $column = $this->views[$viewName]->columnForField($fieldName);
        if (!empty($column)) {
            foreach ($column->widget->values as $value) {
                $result[] = ['key' => $this->i18n->trans($value['title']), 'value' => $value['value']];
            }
        }
        return $result;
    }

    /**
     * Return array with parameters values
     *
     * @param array $keys
     *
     * @return array
     */
    protected function requestGet($keys): array
    {
        $result = [];
        foreach ($keys as $value) {
            $result[$value] = $this->request->get($value);
        }
        return $result;
    }
}

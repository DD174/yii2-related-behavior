<?php
namespace dd174\relatedBehavior;

use Exception;
use yii\base\Behavior;
use yii\base\Model;
use yii\bootstrap\Html;
use yii\db\ActiveRecord;
use yii\db\Transaction;
use yii\helpers\ArrayHelper;

/**
 * Class RelatedBehavior
 * @package common\behaviors
 */
class RelatedBehavior extends Behavior
{
    /**
     * @var ActiveRecord
     */
    public $owner;

    /**
     * релейшины которые будут сохраняться
     * @var array
     */
    public $relations;

    /**
     * сценарии для работы с релейшинами, если надо
     * @var array
     */
    public $scenarios = [];

    /**
     * удалить связанные объекты при удалении основной модели
     * TODO: а если не все связи надо удалять каскадом?!
     * @var bool
     */
    public $deleteCascade = true;

    /**
     * @var Transaction
     */
    private $transaction;

    /**
     * ошибки при сохранение relation
     * @var array
     */
    private $errors = [];

    /**
     * признак, что не переданные релейшины удалять не надо, т.е. только добавляем новые
     * @var bool
     */
    private $onlyAdd = false;

    /**
     * новые данные из load
     * @var array
     */
    private $relationNewValue = [];

    /**
     * заполенный FK для связанных моделей
     * @var array
     */
    private $relationFk = [];

    /**
     * @return array
     */
    public function events()
    {
        // если не заданы relation которые нужно обновлять, то и делать нечиго не надо
        if (!$this->relations) {
            return [];
        }

        return [
            // транзакция (открывается если не открыта)
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeTransaction',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeTransaction',
            // транзакция + удаление
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            // обновление relation + транзакция
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            // транзацкция (закрывается если не открыта раньше)
            ActiveRecord::EVENT_AFTER_DELETE => 'afterTransaction'
        ];
    }

    public function beforeTransaction()
    {
        if (!$this->transaction = $this->owner->getDb()->getTransaction()) {
            $this->transaction = $this->owner->getDb()->beginTransaction();
        }
    }

    public function afterSave()
    {
        $this->mergeRelation();

        $this->afterTransaction();
    }

    public function beforeDelete()
    {
        $this->beforeTransaction();

        $this->mergeRelation(true);
    }

    public function afterTransaction()
    {
        if ($this->hasErrors()) {
            foreach ($this->relations as $relation) {
                if ($this->errors[$relation]) {
                    $this->owner->addErrors($this->errors[$relation]);
                }
            }
        }
        if ($this->transaction !== null) {
            if ($this->hasErrors()) {
                $this->transaction->rollBack();
            } else {
                $this->transaction->commit();
            }
        }
    }

    /**
     * @param bool|false $delete если удаляется оснавная модель
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function mergeRelation($delete = false)
    {
        foreach ($this->relations as $relation) {
            $modelDelete = [];
            $modelSave = [];
            if ($delete && $this->owner->$relation) {
                $modelDelete = $this->owner->$relation;
                // обновляем релейшин, если в CurrentValue он явно задан
            } elseif (key_exists($relation, $this->relationNewValue) && is_array($this->relationNewValue[$relation])) {
                // находим имя PK
                $getter = 'get' . $relation;
                /** @var ActiveRecord $modelClass */
                $modelClass = $this->owner->$getter()->modelClass;
                $pk = $modelClass::primaryKey();
                if (count($pk) > 1) {
                    throw new Exception('Составные ключи не поддерживаются!');
                }
                if (!isset($pk[0])) {
                    throw new Exception('Ключ не задан');
                }
                $pk = $pk[0];
                foreach ($this->relationNewValue[$relation] as $key => $values) {
                    $model = null;
                    // здесь нужно добавить поддержку составных ключей, пример из Yii1
                    /*
                    if (is_array($pk)) {
                        $arrayPk = [];
                        foreach ($pk as $field) {
                            $arrayPk[$field] = isset($data[$field]) ? $data[$field] : null;
                        }
                        $model = $class::model()->findByAttributes($arrayPk);
                    }
                    */
                    if (isset($values[$pk]) && $values[$pk]) {
                        $model = $modelClass::findOne($values[$pk]);
                        $model->scenario = ArrayHelper::getValue(
                            $this->scenarios,
                            $relation . '.update', Model::SCENARIO_DEFAULT
                        );
                    }
                    if (!$model) {
                        $config = [];
                        if (method_exists($modelClass, 'setFormName')) {
                            $config = ['formName' => $key];
                        }
                        $model = new $modelClass($config);
                        $model->scenario = ArrayHelper::getValue(
                            $this->scenarios,
                            $relation . '.create', Model::SCENARIO_DEFAULT
                        );
                    }
                    /** @var ActiveRecord $model */
                    $model->setAttributes(array_merge($this->getRelationFk($relation), $values));
                    $modelSave[] = $model;
                }

                // если не только добавление, но и удаление связанной модели, то сверяем:
                if (!$this->onlyAdd && $this->owner->$relation) {
                    foreach ($this->owner->$relation as $oldModel) {
                        $found = false;
                        /** @var ActiveRecord $newModel */
                        foreach ($modelSave as $newKey => $newModel) {
                            if ($oldModel->primaryKey == $newModel->primaryKey) {
                                $found = true;
                                break;
                            }
                        }
                        // Если запись в текущих данных нет, значит ее нужно удалить.
                        if (!$found) {
                            $modelDelete[] = $oldModel;
                        }
                    }
                }
            }

            /** @var ActiveRecord $model */
            foreach ($modelDelete as $model) {
                if (!$model->delete()) {
                    $this->errors[$relation][] = strip_tags(Html::errorSummary($model));
                }
            }

            /** @var ActiveRecord $model */
            foreach ($modelSave as $model) {
                if (!$model->save()) {
                    $this->errors[$relation][] = strip_tags(Html::errorSummary($model));
                }
            }
        }
    }

    /**
     * Есть ли ошибки?
     * @return bool
     */
    private function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * Заполняет релейшин актуальными данными
     * @param string $relationName - имя релейшина
     * @param array $data
     * @param null $formName
     * @param bool $onlyAdd - только добавление
     */
    public function loadRelation($relationName, array $data, $formName = null, $onlyAdd = false)
    {
        $this->onlyAdd = (bool)$onlyAdd;
        if ($formName === null) {
            $formName = $relationName;
        }

        if ($formName === '') {
            $this->relationNewValue[$relationName] = $data;
        } elseif (key_exists($formName, $data)) {
            $this->relationNewValue[$relationName] = $data[$formName];
        } else {
            $this->relationNewValue[$relationName] = [];
        }
    }

    /**
     * Значения для заполнения поля FK в связанной моделе
     * @param $relation
     * @return mixed
     */
    private function getRelationFk($relation)
    {
        if (!isset($this->relationFk[$relation])) {
            $this->relationFk[$relation] = [];
            $getter = 'get' . $relation;
            foreach ($this->owner->$getter()->link as $fk => $id) {
                $this->relationFk[$relation][$fk] = $this->owner->$id; // получаем ID теущей модели
            }
        }

        return $this->relationFk[$relation];
    }
}

<?php
namespace dd174\relatedBehavior;

use Exception;
use yii\base\Behavior;
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
     * @var ActiveRecordRelationSave
     */
    public $owner;

    /**
     * релейшины которые будут сохраняться
     * @var array
     */
    public $relations;

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
     * @return array
     */
    public function events()
    {
        // если не заланы relation которые нужно обновлять, то и делать нечиго не надо
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

        $this->relations = [];

        $this->mergeRelation(true);
    }

    /**
     * @param bool|false $delete если удаляется оснавная модель
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function mergeRelation($delete = false)
    {
        // тут создаем/меняем/удаляем Relation
        foreach ($this->relations as $relation) {
            if ($delete && $this->deleteCascade) {
                $this->owner->$relation = [];
            }

            /* @var ActiveRecord $modelClass */
            $modelClass = $this->owner->getRelation($relation)->modelClass;
            /* @var ActiveRecordRelationSave $fake */
            $fake = new $modelClass();
            $pk = $fake->getTableSchema()->primaryKey;
            // @todo Смелый допилит поддержку составных PK. (c)
            if (count($pk) != 1) {
                throw new Exception('Invalid PK type.');
            }
            $pk = $pk[0];

            if (!is_array($this->owner->$relation)) {
                continue;
            }

            $foreignKey = $this->getRelationForeignKeysToBaseModel($fake);
            // сохраняем связи, только если они были записаны через ActiveRecordRelationSave::_set
            if (!is_array($this->owner->oldRelationValue[$relation])) {
                continue;
            }
            $delOldRelation = ArrayHelper::map($this->owner->oldRelationValue[$relation], $pk, $pk);

            foreach ($this->owner->$relation as $data) {
                /* @var ActiveRecord $model */
                $model = null;
                if (isset($data[$pk]) && $data[$pk]) {
                    // если обновляем запись, то значит ее удалять не надо
                    if (isset($delOldRelation[$data[$pk]])) {
                        unset($delOldRelation[$data[$pk]]);
                    }
                    $model = $modelClass::findOne(intval($data[$pk]));
                }

                if (!$model) {
                    $model = new $modelClass();
                }

                // если в $data явно указаны FK ключи, то они перебьют значения из $foreignKey
                $model->setAttributes(array_merge($foreignKey, $data));
                if (!$model->save()) {
                    $this->errors[$relation] = $model->getErrors();
                }
            }

            if (is_array($delOldRelation)) {
                foreach ($delOldRelation as $id) {
                    if (!$model = $modelClass::findOne($id)->delete()) {

                    }
                }
            }
        }
    }

    public function afterTransaction()
    {
        if ($this->hasErrors()) {
            $this->owner->addErrors($this->errors);
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
     * Есть ли ошибки?
     * @return bool
     */
    private function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * @param ActiveRecordRelationSave $relation
     * @return array
     */
    private function getRelationForeignKeysToBaseModel($relation)
    {
        $fk = [];
        foreach ($relation->extraFields() as $foreignName) {
            $getter = 'get' . $foreignName;
            if (method_exists($relation, $getter)) {
                if ($this->owner->className() == preg_replace('/(Query)$/', '', $relation->$getter()->className())) {
                    foreach ($relation->$getter()->link as $column => $field) {
                        $fk[$field] = $this->owner->$column;
                    }

                }
            }
        }

        return $fk;
    }
}

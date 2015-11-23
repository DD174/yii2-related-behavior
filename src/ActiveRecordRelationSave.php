<?php

namespace dd174\relatedBehavior;

use yii\db\ActiveRecord;

/**
 * Class ActiveRecordMain
 * @package common\components\ActiveRecordMain
 */
class ActiveRecordRelationSave extends ActiveRecord
{
    /**
     * значение старых relation
     * @var array
     */
    public $oldRelationValue;

    /**
     * если пытаемся записать значение в Relation, то разрешаем писать и сохраняем старое значение
     * это нужно для реализации сохранения связей
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            $this->oldRelationValue[$name] = $this->$name;
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * @inheritdoc
     */
    public function save($runValidation = true, $attributeNames = null)
    {
        if (parent::save($runValidation = true, $attributeNames = null)) {
            return !$this->hasErrors();
        }
        return false;
    }
}

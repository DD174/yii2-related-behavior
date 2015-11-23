Yii2 Related behavior for Yii 2
=========================

Обновляет (создает/изменяет/удаляет) связи модели


Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require dd174/yii2-related-behavior "dev-master"
```

Usage
-----

```
use dd174\relatedBehavior\ActiveRecordRelationSave;

class Model extends ActiveRecordRelationSave
```

add in Model behavior

```
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                ...
                [
                    'class' => RelatedBehavior::class,
                    'relations' => [
                        'relatedName',
                    ],
                ],
```

Credits
-------

Author: Danil DD

Email: dd174work@gmail.com

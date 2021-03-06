Yii2 Related behavior for Yii 2
=========================

Обновляет (создает/изменяет/удаляет) связи модели


Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer require dd174/yii2-related-behavior
```

Usage
-----

in Model behavior

```
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                ...
                [
                    'class' => RelatedBehavior::class,
                    'relations' => ['relatedName'],
                    // optional:
                    'scenarios' => ['relatedName' => ['create' => 'create', 'update' => 'update']],
                ],
```

in Controller

```
$model->loadRelation('relatedName', Yii::$app->request->post(), 'keyPost');
```

Tips
------------

in related Model unique formName

```
private $formName;

/**
 * @param null $unique необходим для получения доступа к файлам ($_FILES) в новой моделе
 */
public function setFormName($unique = null)
{
	$unique = $unique ?: ($this->primaryKey ?: uniqid('new', true));
	$this->formName = parent::formName() . '[' . $unique . ']';
}

/**
 * Составляем свое name, что бы легко использовать на одной странице форму с несколькими экземплярами этой модели
 * @return string
 */
public function formName()
{
	if (!$this->formName) {
		$this->setFormName();
	}

	return $this->formName;
}
```



Credits
-------

Author: Danil DD

Email: dd174work@gmail.com

<?php

use yii\db\Migration;

/** Пишет коробку из params['currentBoxname'] — миграции messenger на него рассчитывают. */
class m260101_000000_create_box_probe extends Migration
{
    public function safeUp()
    {
        $this->createTable('box_probe', ['id' => $this->primaryKey(), 'box' => $this->string()]);
        $this->insert('box_probe', ['box' => Yii::$app->params['currentBoxname'] ?? null]);
    }

    public function safeDown()
    {
        $this->dropTable('box_probe');
    }
}

<?php

use yii\db\Migration;

/** Падает на коробке box_a — уже внутри migrate/up, когда db и currentBoxname подменены. */
class m260101_000001_fail_on_box_a extends Migration
{
    public function safeUp()
    {
        if ((Yii::$app->params['currentBoxname'] ?? null) === 'box_a') {
            throw new \RuntimeException('миграция упала на box_a');
        }

        $this->createTable('box_fail_probe', ['id' => $this->primaryKey()]);
    }

    public function safeDown()
    {
        $this->dropTable('box_fail_probe');
    }
}

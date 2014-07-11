<?php

namespace QafooLabs\Profiler;

class SqlAnonymizerTest extends \PHPUnit_Framework_TestCase
{
    static public function dataSqlQuotes()
    {
        return array(
            array('SELECT 1', 'SELECT ?'),
            array('select * from foo', 'select * from foo'),
            array('SELECT "foo" FROM bar', 'SELECT ? FROM bar'),
            array('SELECT "foo", "bar", "baz" FROM bar', 'SELECT ?, ?, ? FROM bar'),
            array('SELECT "foo", \'bar\', 1234, 17.45 FROM baz', 'SELECT ?, ?, ?, ? FROM baz'),
            array('SELECT "foo" FROM bar WHERE "baz" = 1', 'SELECT ? FROM bar WHERE ? = ?'),
            array('No route found for "GET /bundles/.../exception.css" (from "https://example.com/error")', 'No route found for ? (from ?)'),
            array('Dots. Are. .89 Only. Replaced. 1.2 If occurring. 23.42. In. Floats. 0.2342.', 'Dots. Are. ? Only. Replaced. ? If occurring. ?. In. Floats. ?.')
        );
    }

    /**
     * @dataProvider dataSqlQuotes
     * @test
     */
    public function it_anonymizes_sql_replacing_quotes_with_question_marks($sql, $anonymizedSql)
    {
        $this->assertEquals($anonymizedSql, SqlAnonymizer::anonymize($sql));
    }
}

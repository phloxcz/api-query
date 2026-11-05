<?php

declare(strict_types=1);

/**
 * A database as Firebird usually has it: names in uppercase, not quoted.
 */
function createFirebirdSchema(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach (['OBJEDNAVKY', 'ZAKAZNICI'] as $table) {
        try {
            $pdo->exec("DROP TABLE {$table}");
        } catch (PDOException) {
            // wasn't there
        }
    }

    $pdo->exec('CREATE TABLE ZAKAZNICI (
        ID INTEGER NOT NULL PRIMARY KEY,
        NAZEV VARCHAR(10) NOT NULL,
        KOD CHAR(4) CHARACTER SET WIN1250 NOT NULL,
        ZALOZEN DATE,
        AKTIVNI BOOLEAN NOT NULL,
        KREDIT NUMERIC(9,2),
        PIN VARCHAR(8)
    )');
    $pdo->exec('CREATE TABLE OBJEDNAVKY (
        ID INTEGER NOT NULL PRIMARY KEY,
        ZAKAZNIK_ID INTEGER NOT NULL REFERENCES ZAKAZNICI(ID),
        CENA DOUBLE PRECISION NOT NULL,
        VYTVORENA TIMESTAMP,
        POZNAMKA BLOB SUB_TYPE TEXT
    )');

    foreach ([
        "INSERT INTO ZAKAZNICI VALUES (1, 'Žluťoučký', '0123', '2020-01-02', TRUE, 10.50, '1111')",
        "INSERT INTO ZAKAZNICI VALUES (2, 'Kůň', '1000', NULL, FALSE, NULL, '2222')",
        "INSERT INTO ZAKAZNICI VALUES (3, 'Řeřicha', '20A1', '2021-06-07', TRUE, 5, NULL)",
        "INSERT INTO OBJEDNAVKY VALUES (1, 1, 5, '2024-01-02 03:04:05', 'První')",
        "INSERT INTO OBJEDNAVKY VALUES (2, 1, 2.5, '2024-02-03 00:00:00', NULL)",
        "INSERT INTO OBJEDNAVKY VALUES (3, 2, 7, NULL, '20240203')",
    ] as $sql) {
        $pdo->exec($sql);
    }
}

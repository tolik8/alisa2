prompt PL/SQL Developer Export User Objects for user PIKALKA@REGION19
prompt Created by D09-Turkevych on 11 ������ 2019 �.
set define off
spool passport_dev.log

prompt
prompt Creating package PASSPORT_DEV
prompt =============================
prompt
CREATE OR REPLACE PACKAGE passport_dev IS

    DEBUG$ BOOLEAN := FALSE;

    FUNCTION get_one_row_from_nom_k (guid$ VARCHAR2, nom_sk$ VARCHAR2) RETURN VARCHAR2;
    FUNCTION get_one_row_from_nom_z (guid$ VARCHAR2, nom_sk$ VARCHAR2) RETURN VARCHAR2;
    FUNCTION nom_to_line_k (tin$ NUMBER, guid$ VARCHAR2) RETURN CLOB;
    FUNCTION nom_to_line_z (tin$ NUMBER, guid$ VARCHAR2) RETURN CLOB;

    PROCEDURE create_job (tin$ NUMBER, dt1$ VARCHAR2, dt2$ VARCHAR2, tasks$ VARCHAR2, refresh$ VARCHAR2, guid_user$ VARCHAR2, guid$ VARCHAR2 DEFAULT NULL);

    PROCEDURE delete_by_guid (guid$ VARCHAR2);
    PROCEDURE delete_my_data;
    PROCEDURE delete_old_data;

    PROCEDURE prepare (tin$ NUMBER, dt1$ VARCHAR2, dt2$ VARCHAR2, tasks$ VARCHAR2, refresh$ VARCHAR2, guid_user$ VARCHAR2, guid$ VARCHAR2 DEFAULT '');

    PROCEDURE truncate_all_tables;

END;
/

prompt
prompt Creating package body PASSPORT_DEV
prompt ==================================
prompt
CREATE OR REPLACE PACKAGE BODY passport_dev IS

    GUID#       VARCHAR2(32);
    GUID_USER#  GUID#%TYPE;
    TASKS#      VARCHAR2(50);
    REFRESH#    VARCHAR2(50);
    C_DISTR#    NUMBER;
    TIN#        NUMBER;
    DT1#        VARCHAR2(12);
    DT2#        DT1#%TYPE;
    CR#         VARCHAR2(2) := CHR(13) || CHR(10);

    /* �������� �� �� �������� � ����� ������ ��������� prepare �� ��������� �������� �� �������� ����� */
    FUNCTION check_started RETURN NUMBER
    IS count# NUMBER;
    BEGIN
        SELECT COUNT(*) INTO count# FROM PIKALKA.pass_jrn
        WHERE tin = TIN# AND dt1 = DT1# AND dt2 = DT2# AND tm IS NULL;

        RETURN count#;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� 1 ����� �� ����������� � ���������� ��������� (������) */
    FUNCTION get_one_row_from_nom_k (guid$ VARCHAR2, nom_sk$ VARCHAR2) RETURN VARCHAR2
    IS FResult VARCHAR2(500);
    BEGIN
        SELECT SUBSTR(RG3S_D2RG3S, 0, 500) INTO FResult
        FROM PIKALKA.pass_kontr_kredit_1
        WHERE guid = guid$
            AND nom_sk = nom_sk$
            AND ROWNUM = 1;

        RETURN FResult;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� 1 ����� �� ����������� � ���������� ��������� (�����������) */
    FUNCTION get_one_row_from_nom_z (guid$ VARCHAR2, nom_sk$ VARCHAR2) RETURN VARCHAR2
    IS FResult VARCHAR2(500);
    BEGIN
        SELECT SUBSTR(RG3S_D2RG3S, 0, 500) INTO FResult
        FROM PIKALKA.pass_kontr_zobov_1
        WHERE guid = guid$
            AND nom_sk = nom_sk$
            AND ROWNUM = 1;

        RETURN FResult;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* ���������� ������������ � ���� ����� (������) */
    FUNCTION nom_to_line_k (tin$ NUMBER, guid$ VARCHAR2) RETURN CLOB
    IS FResult CLOB;
    BEGIN
        SELECT REPLACE(sys_xmlagg(XMLELEMENT(col, nom||' || ')).extract('/ROWSET/COL/text()').getclobval(), ';', '"') INTO FResult
        FROM (
            SELECT nom, obsag
            FROM PIKALKA.pass_kontr_kredit_2
            WHERE guid = guid$ AND tin = tin$
            ORDER BY obsag DESC
        );
        RETURN FResult;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* ���������� ������������ � ���� ����� (�����������) */
    FUNCTION nom_to_line_z (tin$ NUMBER, guid$ VARCHAR2) RETURN CLOB
    IS FResult CLOB;
    BEGIN
        SELECT REPLACE(sys_xmlagg(XMLELEMENT(col, nom||' || ')).extract('/ROWSET/COL/text()').getclobval(), ';', '"') INTO FResult
        FROM (
            SELECT nom, obsag
            FROM PIKALKA.pass_kontr_zobov_2
            WHERE guid = guid$ AND cp_tin = tin$
            ORDER BY obsag DESC
        );
        RETURN FResult;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* ������� ����������� */
    PROCEDURE console (input$ VARCHAR2)
    IS
    BEGIN
        IF DEBUG$ THEN DBMS_OUTPUT.put_line(input$); END IF;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� JOB ���� ��������� ��������� prepare */
    PROCEDURE create_job (tin$ NUMBER, dt1$ VARCHAR2, dt2$ VARCHAR2, tasks$ VARCHAR2, refresh$ VARCHAR2, guid_user$ VARCHAR2, guid$ VARCHAR2 DEFAULT NULL)
    IS
        count#   NUMBER;
        job_n#   NUMBER := 0;
        job#     VARCHAR2(2000);
        pref#    VARCHAR2(10) := '--GUID=';
        package# VARCHAR2(32);
    BEGIN
        BEGIN
            /* ��������� �� ��� ���������� �� �� ��� ���������� ����� */
            SELECT COUNT(*) INTO count# FROM user_jobs j
            WHERE SUBSTR(j.what, 8, INSTR(j.what, CR#)-8) = guid_user$;
        EXCEPTION
            WHEN OTHERS THEN count# := 0;
        END;

        /* �������� ��� ������ (passport ��� passport_dev) */
        package# := $$PLSQL_UNIT;

        IF count# = 0 THEN
            job# := pref# || guid_user$ || CR# || 'BEGIN PIKALKA.' || package# || '.prepare('
                || tin$ || ', '
                || qq.q(dt1$) || ', '
                || qq.q(dt2$) || ', '
                || qq.q(tasks$) || ', '
                || qq.q(refresh$) || ', '
                || qq.q(guid_user$) || ', '
                || qq.q(guid$)
                || '); END;';
            console(job#);
            /* �������� JOB ���� ��������� ��������� prepare */
            DBMS_JOB.submit(job_n#, job#);
            COMMIT;
        END IF;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� ���� ��� */
    PROCEDURE delete_bad_data
    IS
        sql# VARCHAR2(500);
    BEGIN
        FOR cur IN (SELECT tbl FROM PIKALKA.d_sql WHERE project_id = 1 ORDER BY id) LOOP
            sql# := 'DELETE FROM PIKALKA.' || cur.tbl || ' WHERE guid NOT IN (SELECT guid FROM PIKALKA.pass_jrn)';
            BEGIN
                EXECUTE IMMEDIATE sql#;
            EXCEPTION
                WHEN OTHERS THEN
                    console(sql# || CR# || SQLERRM);
            END;
        END LOOP;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� ��� �� GUID */
    PROCEDURE delete_by_guid (guid$ VARCHAR2)
    IS
        sql# VARCHAR2(500);
    BEGIN
        DELETE FROM PIKALKA.pass_jrn WHERE guid = guid$;
        DELETE FROM PIKALKA.pass_steps WHERE guid = guid$;
        DELETE FROM PIKALKA.pass_task WHERE guid = guid$;

        FOR cur IN (SELECT tbl FROM PIKALKA.d_sql WHERE project_id = 1 ORDER BY id) LOOP
            sql# := 'DELETE FROM PIKALKA.' || cur.tbl || ' WHERE guid = ''' || guid$ || '''';
            BEGIN
                EXECUTE IMMEDIATE sql#;
            EXCEPTION
                WHEN OTHERS THEN
                    console(sql# || CR# || SQLERRM);
            END;
        END LOOP;

        COMMIT;
        console('DELETE FROM ALL TABLES BY guid');
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� �� ��� */
    PROCEDURE delete_my_data
    IS
    BEGIN
        DELETE FROM PIKALKA.pass_log WHERE guid = '06F2EF58972B2E32E050130A64136A5F' OR tin = 300400;
        DELETE FROM PIKALKA.pass_jrn WHERE guid = '06F2EF58972B2E32E050130A64136A5F' OR tin = 300400;
        DELETE FROM PIKALKA.pass_steps WHERE guid NOT IN (SELECT guid FROM PIKALKA.pass_jrn);
        DELETE FROM PIKALKA.pass_task WHERE guid NOT IN (SELECT guid FROM PIKALKA.pass_jrn);
        delete_bad_data;
        COMMIT;
        console('DELETE my data FROM ALL TABLES');
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� ���� ��� */
    PROCEDURE delete_old_data
    IS
    BEGIN
        DELETE FROM PIKALKA.pass_jrn WHERE dt0 < SYSDATE - 7;
        DELETE FROM PIKALKA.pass_steps WHERE guid NOT IN (SELECT guid FROM PIKALKA.pass_jrn);
        DELETE FROM PIKALKA.pass_task WHERE guid NOT IN (SELECT guid FROM PIKALKA.pass_jrn);
        delete_bad_data;
        COMMIT;
        console('DELETE old data FROM ALL TABLES');
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� ������� � ��� (pass_errors) */
    PROCEDURE error_to_log (mess$ CLOB DEFAULT NULL, type$ NUMBER DEFAULT 0)
    IS
        PRAGMA AUTONOMOUS_TRANSACTION;
        mess# CLOB;
        type# NUMBER := 0;
    BEGIN
        mess# := mess$;
        IF INSTR(mess#, 'ORA-') > 0 then
            type# := -1;
        ELSE
            type# := type$;
        END IF;

        INSERT INTO PIKALKA.pass_errors(mess, typs) VALUES (mess#, type#);
        COMMIT;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� SQL */
    PROCEDURE execute_sql (sql_id$ NUMBER)
    IS
        sql_id#     NUMBER;
        sql_table#  VARCHAR2(30);
        sql_name#   VARCHAR2(50);
        sql_text#   CLOB;
        error_text# CLOB;
    BEGIN
        SELECT id, NAME, text, tbl
        INTO sql_id#, sql_name#, sql_text#, sql_table#
        FROM PIKALKA.d_sql
        WHERE project_id = 1 AND id = sql_id$;

        sql_text# := REPLACE(sql_text#, ':guid', qq.q(GUID#));
        sql_text# := REPLACE(sql_text#, ':tin', TIN#);
        sql_text# := REPLACE(sql_text#, ':dt1', qq.q(DT1#));
        sql_text# := REPLACE(sql_text#, ':dt2', qq.q(DT2#));
        sql_text# := REPLACE(sql_text#, ':c_distr', C_DISTR#);

        BEGIN
            EXECUTE IMMEDIATE TO_CHAR(sql_text#);
            console('Ok! ' || sql_name#);
        EXCEPTION
            WHEN OTHERS THEN
                error_text# := 'ID:' || sql_id# || ' TABLE:' || sql_table# || ' NAME:' || sql_name# || CR#||CR# || SQLERRM || CR#||CR# || sql_text#;
                console(error_text#);
                IF NOT DEBUG$ THEN
                   error_to_log(error_text#);
                END IF;
        END;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� ������ */
    PROCEDURE execute_task(id$ NUMBER)
    IS
    BEGIN
        FOR cur IN (
            SELECT sql_id FROM PIKALKA.d_pass_task_sql WHERE task_id = id$ ORDER BY sql_id
        ) LOOP
            execute_sql(cur.sql_id);
        END LOOP;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* ������� ��� */
    PROCEDURE session_begin
    IS
        PRAGMA AUTONOMOUS_TRANSACTION;
    BEGIN
        INSERT INTO PIKALKA.pass_jrn (guid, dt1, dt2, tin, guid_user, tasks)
            VALUES (GUID#, DT1#, DT2#, TIN#, GUID_USER#, TASKS#);
        COMMIT;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* ʳ���� ��� */
    PROCEDURE session_end (total_time_begin$ TIMESTAMP)
    IS
        PRAGMA AUTONOMOUS_TRANSACTION;
        seconds# NUMBER;
    BEGIN
        /* ��������� ������ ���� ������� ��������� ����� */
        seconds# := ROUND(qq.timestamp_diff(total_time_begin$, SYSTIMESTAMP), 0);
        console('Seconds - ' || TO_CHAR(seconds#));

        INSERT INTO PIKALKA.pass_log (guid, dt1, dt2, tin, guid_user, tm, prepared, tasks)
            VALUES (GUID#, DT1#, DT2#, TIN#, GUID_USER#, seconds#, 1, TASKS#);
        UPDATE PIKALKA.pass_jrn SET tm = seconds# WHERE guid = GUID#;
        COMMIT;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� ������ */
    PROCEDURE task_create
    IS
        PRAGMA AUTONOMOUS_TRANSACTION;
    BEGIN
        INSERT INTO PIKALKA.pass_task (guid, task_id, tm, dt1, dt2, tin, guid_user, guid_ready)
        SELECT GUID#, x1.id, x3.tm, DT1#, DT2#, TIN#, GUID_USER#, x3.guid_ready
        FROM
            (SELECT id FROM PIKALKA.d_pass_task WHERE qq.in_comma_string(id, TASKS#) = 1) x1,

            (SELECT task_id FROM PIKALKA.pass_access WHERE guid = GUID_USER#) x2,

            (SELECT task_id, guid AS guid_ready, 0 AS tm
            FROM PIKALKA.pass_task
            WHERE tin = TIN# AND dt1 = DT1# AND dt2 = DT2#
                AND task_id || TO_CHAR(dt0, ' - dd.mm.yyyy hh24:mi:ss') IN (
                    SELECT task_id || TO_CHAR(MAX(dt0), ' - dd.mm.yyyy hh24:mi:ss') dt0
                    FROM PIKALKA.pass_task
                    WHERE guid_ready IS NULL AND tin = TIN# AND dt1 = DT1# AND dt2 = DT2#
                        AND qq.in_comma_string(task_id, TASKS#) = 1
                        AND qq.in_comma_string(task_id, REFRESH#) = 0
                    GROUP BY task_id
                )
            ) x3
        WHERE x1.id = x2.task_id AND x1.id = x3.task_id(+);

        COMMIT;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* ������� ��� ��� ������ */
    PROCEDURE task_update(guid$ VARCHAR2, task_id$ NUMBER, seconds$ NUMBER)
    IS
        PRAGMA AUTONOMOUS_TRANSACTION;
    BEGIN
        UPDATE PIKALKA.pass_task SET tm = seconds$ WHERE guid = guid$ AND task_id = task_id$;
        COMMIT;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

    /* �������� �� ������� */
    PROCEDURE truncate_all_tables
    IS
        TYPE array_t IS VARRAY(3) OF VARCHAR2(30);
        ARRAY array_t := array_t('jrn', 'steps', 'errors');
        sql# VARCHAR2(500);
    BEGIN
        FOR i IN 1..array.count LOOP
            sql# := 'TRUNCATE TABLE PIKALKA.pass_' || ARRAY(i);
            --EXECUTE IMMEDIATE sql#;
        END LOOP;

        FOR cur IN (
            SELECT tbl FROM PIKALKA.d_sql WHERE project_id = 1 ORDER BY id
        ) LOOP
            sql# := 'TRUNCATE TABLE PIKALKA.' || cur.tbl;
            BEGIN
                EXECUTE IMMEDIATE sql#;
            EXCEPTION
                WHEN OTHERS THEN
                    console(sql# || CR# || SQLERRM);
            END;
        END LOOP;

        console('TRUNCATE ALL TABLES');
    END;
    /* ////////////////////////////////////////////////////////////////////// */



    /* ϳ�������� ����� (����� ��������� �������� ��� ����� ��������) */
    PROCEDURE prepare (tin$ NUMBER, dt1$ VARCHAR2, dt2$ VARCHAR2, tasks$ VARCHAR2, refresh$ VARCHAR2, guid_user$ VARCHAR2, guid$ VARCHAR2 DEFAULT '')
    IS
        cnt# NUMBER;
        cnt_users# NUMBER;
        time_begin# TIMESTAMP := SYSTIMESTAMP;
        total_time_begin# TIMESTAMP := SYSTIMESTAMP;
        seconds# NUMBER;
    BEGIN
        TIN# := tin$;
        DT1# := dt1$;
        DT2# := dt2$;
        TASKS# := tasks$;
        REFRESH# := refresh$;
        GUID_USER# := guid_user$;

        /* �������� �� ����� � ���������� � �������� GUID */
        SELECT COUNT(*) INTO cnt_users# FROM PIKALKA.people WHERE guid = guid_user$;

        IF cnt_users# != 1 THEN
            console('USER BY GUID ' || guid_user$ || ' NOT FOUND');
            RETURN;
        END IF;

        /* �������� �� �� ������ � ����� ������ ��������� ���������� �� �������� ����� */
        cnt# := check_started;
        IF cnt# > 0 THEN console('��������� PREPARE ������ � ����� ������ �� �������� ' || tin$); RETURN; END IF;

        /* �������� �� ���� ��� �� ��������� ���� �� �������� ����� */
        --delete_old_data;

        /* ������ C_DISTR */
        C_DISTR# := tax.get_dpi_by_tin(tin$);
        IF C_DISTR# = 0 THEN console('TAXPAYER ' || TO_CHAR(tin$) || ' NOT FOUND'); RETURN; END IF;

        /* ����� GUID ������ � ��������� ��� �������� ����� */
        GUID# := NVL(guid$, SYS_GUID());
        console('GUID - ' || GUID#);

        /* �������� � ������ pass_jrn (���� ��������� ���������) */
        session_begin;

        /* �������� ������ � ������� pass_task, �� ���� ������ ������� �������� (d_pass_task, d_pass_task_sql) */
        task_create;

        FOR cur IN (
            SELECT task_id FROM PIKALKA.pass_task
            WHERE guid = GUID# AND guid_ready IS NULL
            ORDER BY task_id
        ) LOOP
            time_begin# := SYSTIMESTAMP;
            execute_task(cur.task_id);
            seconds# := ROUND(qq.timestamp_diff(time_begin#, SYSTIMESTAMP), 3);
            task_update(GUID#, cur.task_id, seconds#);
        END LOOP;

        /* ����� � ��� (�� ��������� �����) */
        session_end(total_time_begin#);

        DELETE FROM PIKALKA.pass_kontr_kredit_1 WHERE guid = GUID#;
        DELETE FROM PIKALKA.pass_kontr_kredit_2 WHERE guid = GUID#;
        DELETE FROM PIKALKA.pass_kontr_zobov_1 WHERE guid = GUID#;
        DELETE FROM PIKALKA.pass_kontr_zobov_2 WHERE guid = GUID#;

        COMMIT;
    END;
    /* ////////////////////////////////////////////////////////////////////// */

END;
/


prompt Done
spool off
set define on

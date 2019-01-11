-- passport/insert/pov.sql
INSERT INTO PIKALKA.pasp_pov_old
SELECT :guid, pin, name, SUM(t) t, 
    CASE SUM(t) 
        WHEN 1 THEN '��������'
        WHEN 2 THEN '���������'
        WHEN 3 THEN '���,���'
        WHEN 4 THEN '���������'
        WHEN 5 THEN '���,���'
        WHEN 6 THEN '���,���'
        WHEN 7 THEN '���,���,���'
        WHEN 8 THEN '���������'
        ELSE '�������'
    END typ
FROM
    (SELECT LPAD(pin,10,'0') pin, name, c_post t 
    FROM RG02.r21manager WHERE c_distr = :c_distr AND tin = :tin
    
    UNION SELECT LPAD(pin_found,10,'0'), name, 4 
    FROM RG02.r21pfound WHERE c_distr = :c_distr AND tin = :tin AND pin_found IS NOT NULL
    
    UNION SELECT TO_CHAR(tin_found), name, 8 
    FROM RG02.r21pfound WHERE c_distr = :c_distr AND tin = :tin AND tin_found IS NOT NULL)
GROUP BY pin, name
-- pasport/insert_pasp_kontr_kre2.sql
INSERT INTO PIKALKA.pasp_kontr_kre2
SELECT guid, cp_tin, tin,
    MIN(crtdate) mind,
    MAX(crtdate) maxd,
    ROUND((SUM(rg010)+(SUM(rg010)*0.2))/1000,0) obsag,
    ROUND((SUM(rg010)+(SUM(rg010)*0.2))/1000,0) || ' ��� ' || '(' || COUNT(*) || ') ' ||
    PIKALKA.get_one_row_from_nom_k(:guid, nom_sk) nom
FROM PIKALKA.pasp_kontr_kre1
WHERE guid = :guid
GROUP BY guid, cp_tin, tin, nom_sk
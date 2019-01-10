<?php

namespace App\controllers;

use alhimik1986\PhpExcelTemplator\PhpExcelTemplator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\QueryBuilder;
use App\Twig;
use App\MyUser;
use App\Breadcrumb;

class Pasport
{
    private $root;
    protected $twig;
    protected $db;
    protected $myUser;
    protected $bc;
    protected $role = '22'; // ���� 22 - ������� ��������
    protected $x;
    protected $new_guid;
    protected $c_distr;
    protected $ss;

    public function __construct (Twig $twig, QueryBuilder $db, MyUser $myUser, Breadcrumb $bc)
    {
        $this->root = $_SERVER['DOCUMENT_ROOT'];
        $this->twig = $twig;
        $this->db = $db;
        $this->myUser = $myUser;
        $this->bc = $bc;
        $access = in_string($this->role, $this->myUser->roles);
        $this->x['title'] = '�������';

        if (!$access) {
            $this->twig->showTemplate('index.html', ['my' => $this->myUser]);
            if (DEBUG) {d($this);}
            Exit;
        }

        if ($this->bc->isUnderConstruct) {
            try {
                $this->x['img_number'] = random_int(0, 9);
            } catch (\Exception $e) {
                $this->x['img_number'] = 0;
            }
            $this->twig->showTemplate('isUnderConstruct.html', ['x' => $this->x, 'my' => $this->myUser]); Exit;
        }
    }

    public function pasport (): void
    {
        $this->x['menu'] = $this->bc->getMenu('pasport');
        $this->twig->showTemplate('pasport/pasport.html', ['x' => $this->x, 'my' => $this->myUser]);
        if (DEBUG) {d($this);}
    }

    public function check (): void
    {
        $this->x['menu'] = $this->bc->getMenu('check');
        $this->x['post'] = $params = $this->getPost();

        $sql = file_get_contents($this->root . '/sql/pasport/check.sql');
        $this->x['data'] = $this->db->getOneRowFromSQL($sql, $params);

        if (!$this->db->resultIsOk) {
            $this->twig->showTemplate('error.html', ['x' => $this->x, 'my' => $this->myUser]); Exit;
            /** @noinspection PhpUnreachableStatementInspection */
            if (DEBUG) {d($this);}
        }

        if (empty($this->x['data'])) {
            $_SESSION['post'] = $params;
            // Passport not found, prepare passport
            header('Location: /pasport/prepare');
        } else {
            /** @noinspection NestedPositiveIfStatementsInspection */
            if ($this->x['data']['TM'] === null) {
                // Passport created at the moment
                header('Location: /pasport/loading/'.$this->x['data']['GUID']);
            }
        }
        // Passport exists, show the choice between "Use existing" and "Generate new"
        $this->twig->showTemplate('pasport/check.html', ['x' => $this->x, 'my' => $this->myUser]);
        if (DEBUG) {d($this);}
    }

    public function job (): void
    {
        /*$sql = "DECLARE job_n# NUMBER;
BEGIN 
    job_n# := pasport.create_job(300400, '01.01.2017', '31.12.2018', '06F2EF58972B2E32E050130A64136A5F');
END;";*/
        $sql = 'DECLARE job_n# NUMBER;
BEGIN 
    job_n# := pasport.create_job(:tin, :dt1, :dt2, :guid);
END;';
        $data = [
            'tin' => '300400',
            'dt1' => '01.01.2017',
            'dt2' => '31.12.2018',
            'guid' => '06F2EF58972B2E32E050130A64136A5F',
        ];

        //$result = $this->db->getAllFromSQL($sql, $data);
        $result = $this->db->runSQL($sql, $data);
        vd($result);

    }

    public function loading ($guid): void
    {
        $this->x['menu'] = $this->bc->getMenu('loading');
//        echo $guid;
        $loading_index = 1;
//        $loading_index = random_int(1,12);
//        echo $loading_index;
        if ($loading_index < 10) {$this->x['loading_index'] = 'a0'.$loading_index;} else {$this->x['loading_index'] = 'a'.$loading_index;}

        $this->x['guid'] = $guid;
        $this->twig->showTemplate('pasport/loading.html', ['x' => $this->x, 'my' => $this->myUser]);
    }

    public function ajax ($guid): void
    {
        $params = ['guid' => $guid];
        $sql = 'SELECT COUNT(*) FROM PIKALKA.pasp_jrn WHERE guid = :guid AND tm IS NOT NULL';
        $cnt = $this->db->getOneValueFromSQL($sql, $params);
        if ($cnt === '1') {
            $tm = $this->db->getOneValue('tm', 'PIKALKA.pasp_jrn', $params);
            echo 'FINISH ' . $tm;
        } else {
            //$this->x['pasp_steps'] = $this->db->getAll('PIKALKA.pasp_steps', ['guid' => $guid], 'step');
            $sql = 'SELECT * FROM PIKALKA.pasp_steps WHERE guid = :guid and step > 0 ORDER BY step';
            $this->x['pasp_steps'] = $this->db->getAllFromSQL($sql, ['guid' => $guid]);
            $this->twig->showTemplate('pasport/ajax.html', ['x' => $this->x]);
        }
    }

    public function prepare (): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->x['data'] = $params = $this->getPost();
        } else {
            if (!isset($_SESSION['post'])) {header('Location: /pasport');}
            $params = $_SESSION['post'];
            unset($_SESSION['post']);
        }
        $new_guid = $this->db->getNewGUID();
        $sql = 'BEGIN pasport.create_job(:tin, :dt1, :dt2, :user_guid, :guid); END;';
        $params = array_merge($params, ['user_guid' => $this->myUser->guid, 'guid' => $new_guid]);
        $this->db->runSQL($sql, $params);
        header('Location: /pasport/loading/' . $new_guid);
    }

    public function prepare_old (): void
    {
        $start_time = microtime(true);
        $this->x['menu'] = $this->bc->getMenu('prepare');
        $this->x['guid'] = $this->new_guid = $this->db->getNewGUID();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->x['data'] = $params = $this->getPost();
        } else {
            if (!isset($_SESSION['post'])) {header('Location: /pasport');}
            $params = $_SESSION['post'];
            unset($_SESSION['post']);
        }

        $sql = 'SELECT PIKALKA.get_dpi_by_tin(:tin) FROM dual';
        $this->c_distr = $this->db->getOneValueFromSQL($sql, $params);

        // ϳ�������� ����� - ����������� (������)
        $this->prepareKontr($params, 'kre');
        // ϳ�������� ����� - ����������� (�����������)
        $this->prepareKontr($params, 'zob');
        // ϳ�������� ����� - ������
        $this->prepareBalance($params);
        // ϳ�������� ����� - ��������
        $this->preparePributok($params);
        // ϳ�������� ����� - ���
        $this->prepareESV($params);
        // ϳ�������� ����� - ���������
        $this->preparePov($params);

        $sql_params = [
            'guid' => $this->new_guid, 'dt1' => $params['dt1'], 'dt2' => $params['dt2'],
            'tin' => $params['tin'], 'guid_user' => $this->myUser->guid,
        ];

        // ����� � post_jrn
        $this->db->insert('PIKALKA.pasp_jrn', $sql_params);

        $this->x['prepare_time'] = round(microtime(true) - $start_time, 4);
        $sql_params['tm'] = str_replace('.', ',', $this->x['prepare_time']);

        // ����� � post_log
        $this->db->insert('PIKALKA.pasp_log', $sql_params);

        if (empty($this->x['prepare_errors'])) {
            $this->twig->showTemplate('pasport/prepared.html', ['x' => $this->x, 'my' => $this->myUser]);
        } else {
            $this->twig->showTemplate('error.html', ['x' => $this->x, 'my' => $this->myUser]);
        }
        if (DEBUG) {d($this);}
    }

    public function toExcel (): void
    {
        $templateFile = $this->root . '/xls/pasport/template.xlsx';
        $outputFile = './pasport.xlsx';
        $outputMethod = true;
        $default_params = $templateVars = [];

        try {$this->ss = IOFactory::load($templateFile);}
        catch (\Exception $e) {echo $e->getMessage(); Exit;}

        try {
            for ($i = 0; $i <= $this->ss->getSheetCount()-1; $i++) {
                $templateVars[$i+1] = $this->ss->getSheet($i)->toArray();
                $default_params[$i+1] = $this->prepareParams($templateVars[$i+1]);
            }
        } catch (\Exception $e) {echo $e->getMessage(); Exit;}

        $pattern = '#^[0-9a-zA-Z]{32}$#';
        $this->new_guid = regex($pattern, $_POST['guid'], 0);

        $params = $this->db->getOneRow('PIKALKA.pasp_jrn', ['guid' => $this->new_guid]);
        $guid_param = ['guid' => $this->new_guid];

        $input_xlsParams = ['{tin}' => $params['TIN'], '{dt1}' => $params['DT1'], '{dt2}' => $params['DT2']];

        // ����������� ����
        $reg_data = $this->excelRegData($params);
        $params_from_ora = array_merge($input_xlsParams, $reg_data);
        try {$sheet1 = $this->ss->getSheet(0);}
        catch (\Exception $e) {echo $e->getMessage(); Exit;}
        $templateParams = array_merge($default_params[1], $params_from_ora);
        PhpExcelTemplator::renderWorksheet($sheet1, $templateVars[1], $templateParams);

        // �����������
        $sql = 'SELECT ROWNUM n, t.* FROM (SELECT * FROM PIKALKA.pasp_kontr_kredit_3 WHERE guid = :guid ORDER BY obs DESC, tin) t';
        $params_01 = $this->excelKontr($sql, $params, 'T1.');
        $sql = 'SELECT ROWNUM n, t.* FROM (SELECT * FROM PIKALKA.pasp_kontr_zobov_3 WHERE guid = :guid ORDER BY obs DESC, cp_tin) t';
        $params_02 = $this->excelKontr($sql, $params, 'T2.');
        $params_from_ora = array_merge($params_01, $params_02);
        try {$sheet2 = $this->ss->getSheet(1);}
        catch (\Exception $e) {echo $e->getMessage(); Exit;}
        $templateParams = array_merge($default_params[2], $params_from_ora);
        PhpExcelTemplator::renderWorksheet($sheet2, $templateVars[2], $templateParams);

        // ������
        $array = $this->db->getAll('PIKALKA.pasp_balance', $guid_param, 'period_year, period_month');
        $this->setSheet(3, $array);

        // ��������
        $array = $this->db->getAll('PIKALKA.pasp_pributok', $guid_param, 'period_year, period_month');
        $this->setSheet(4, $array);

        // ���
        $array = $this->db->getAll('PIKALKA.pasp_esv', $guid_param, 'period');
        $this->setSheet(5, $array);

        // ��������
        /*$sql = file_get_contents($this->root . '/sql/pasport/pov_t1.sql');
        $array1 = $this->db->getAllFromSQL($sql, $params);
        $array1 = $this->transform1($array1);
        $array1 = $this->transform2($array1, 'T1.');*/

        $sql = 'SELECT t.*, \'\' blank FROM PIKALKA.pasp_pov_t1 t WHERE guid = :guid ORDER BY c_distr, tin, c_stan';
        $array1 = $this->db->getAllFromSQL($sql, $guid_param);
        $array1 = $this->transform1($array1);
        $array1 = $this->transform2($array1, 'T1.');

        $array2 = $this->db->getAll('PIKALKA.pasp_pov_t2', $guid_param, 't DESC, tin, c_distr, c_stan, c_post');
        $array2 = $this->transform1($array2);
        $array2 = $this->transform2($array2, 'T2.');

        $array3 = $this->db->getAll('PIKALKA.pasp_pov_t3', $guid_param, 't DESC, tin, c_distr, c_stan, c_post');
        $array3 = $this->transform1($array3);
        $array3 = $this->transform2($array3, 'T3.');

        $array4 = $this->db->getAll('PIKALKA.pasp_pov_t4', $guid_param, 't DESC, tin, c_distr, c_stan, c_post');
        $array4 = $this->transform1($array4);
        $array4 = $this->transform2($array4, 'T4.');

        /*$sql = file_get_contents($this->root . '/sql/pasport/pov_t5.sql');
        $array5 = $this->db->getAllFromSQL($sql, $params);
        $array5 = $this->transform1($array5);
        $array5 = $this->transform2($array5, 'T5.');*/

        $array5 = $this->db->getAll('PIKALKA.pasp_pov_t5', $guid_param, 't DESC, tin, c_distr, c_stan');
        $array5 = $this->transform1($array5);
        $array5 = $this->transform2($array5, 'T5.');

        $params_from_ora = array_merge($array1, $array2, $array3, $array4, $array5);
        try {$sheet6 = $this->ss->getSheet(5);}
        catch (\Exception $e) {echo $e->getMessage(); Exit;}
        $templateParams = array_merge($default_params[6], $params_from_ora);
        PhpExcelTemplator::renderWorksheet($sheet6, $templateVars[6], $templateParams);

        // ����� � post_log
        $sql_params = [
            'guid' => $this->new_guid, 'dt1' => $params['DT1'], 'dt2' => $params['DT2'],
            'tin' => $params['TIN'], 'guid_user' => $this->myUser->guid, 'tm' => 0,
        ];
        $this->db->insert('PIKALKA.pasp_log', $sql_params);

        if ($outputMethod) {PhpExcelTemplator::outputSpreadsheetToFile($this->ss, $outputFile);}
        else {PhpExcelTemplator::saveSpreadsheetToFile($this->ss, $outputFile);}
    }

    protected function setSheet ($index, $array): void
    {
        $array = $this->transform1($array);
        $params_from_ora = $this->transform2($array);
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $sheet = $this->ss->getSheet($index - 1);
            /** @noinspection PhpUndefinedMethodInspection */
            $templateVars = $sheet->toArray();
            $default_params = $this->prepareParams($templateVars);
        } catch (\Exception $e) {
            echo $e->getMessage(); Exit;
        }
        $templateParams = array_merge($default_params, $params_from_ora);
        PhpExcelTemplator::renderWorksheet($sheet, $templateVars, $templateParams);
    }

    protected function excelRegData ($input_params): array
    {
        $tin = $params['TIN'] = $input_params['TIN'];

        $sql = 'SELECT PIKALKA.get_dpi_by_tin(:tin) FROM dual';
        $dpi = $this->c_distr = $this->db->getOneValueFromSQL($sql, $params);
        $type_pl = (int) $this->db->getOneValue('FACE_MODE','RG02.r21taxpay', ['tin' => $tin, 'c_distr' => $dpi]);

        $r21taxpay = $this->db->getOneRow('RG02.r21taxpay', ['tin' => $tin, 'c_distr' => $dpi]);

        $stan_name = $this->db->getOneValue('N_STAN','ETALON.E_S_STAN', ['c_stan' => $r21taxpay['C_STAN']]);
        $kved_name = $this->db->getOneValue('NU','ETALON.E_KVED', ['kod' => $r21taxpay['KVED']]);

        $sql = 'SELECT AISR.rpp_util.getfulladdress(c_city,t_street,c_street,house,house_add,unit,apartment) adr '.chr(10).
            'FROM RG02.r21paddr WHERE tin = :tin AND c_distr = :c_distr AND c_adr = 1';
        $address = $this->db->getOneValueFromSQL($sql, ['tin' => $tin, 'c_distr' => $dpi]);

        if ($type_pl === 1) {
            $sql = 'SELECT c_post, pin, name, n_tel FROM RG02.r21manager WHERE tin = :tin';
            $r21manager = $this->db->getKeyValuesFromSQL($sql, $params);
            $reg_params_ur = [
                '{r21manager.dir}' => utf8($r21manager[1]['NAME']),
                '{r21manager.buh}' => utf8($r21manager[2]['NAME']),
                '{r21manager.dir_tel}' => utf8($r21manager[1]['N_TEL']),
                '{r21manager.buh_tel}' => utf8($r21manager[2]['N_TEL']),
            ];
        } else {
            $reg_params_ur = [];
        }

        $sql = file_get_contents($this->root . '/sql/pasport/get_r21stan_h.sql');
        $array = $this->db->getAllFromSQL($sql, ['tin' => $tin, 'c_distr' => $dpi]);
        $array = $this->transform1($array);
        $stan_h = $this->transform2($array, 'SH.');

        $reg_params = [
            '{r21taxpay.c_distr}' => $this->c_distr,
            '{r21taxpay.name}' => utf8($r21taxpay['NAME']),
            '{r21taxpay.stan}' => $r21taxpay['C_STAN'],
            '{r21taxpay.stan_name}' => utf8($stan_name),
            '{r21taxpay.kved}' => $r21taxpay['KVED'],
            '{r21taxpay.kved_name}' => utf8($kved_name),
            '{r21taxpay.d_reg_sti}' => $r21taxpay['D_REG_STI'],
            '{r21paddr.address}' => utf8($address),
        ];

        $sql = 'SELECT * FROM AISR.pdv_act_r WHERE tin = :tin AND dat_anul IS NULL AND ROWNUM = 1';
        $pdv_act_r = $this->db->getOneRowFromSQL($sql, $params);
        if (!empty($pdv_act_r)) {$reg_params['{pdv_act_r.dat_reestr}'] = $pdv_act_r['DAT_REESTR'];}

        return array_merge($reg_params, $reg_params_ur, $stan_h);
    }

    protected function prepareKontr ($params, $type): bool
    {
        $prepared = false;

        $guid_param = ['guid' => $this->new_guid];
        $params = array_merge($params, $guid_param);

        $count = [];
        for ($i=1; $i<=3; $i++) {
            $count[$i] = $this->db->getCount('PIKALKA.pasp_kontr_'.$type.$i, $guid_param);
            if ($count[$i] === 0) {
                $sql = file_get_contents($this->root . '/sql/pasport/insert/kontr_'.$type.$i.'.sql');
                $this->db->runSQL($sql, $params);
            }
        }

        if ($count[3] > 0) {$prepared = true; return $prepared;}

        if ($this->db->errors_count === 0) {$prepared = true;}
        else {$this->x['prepare_errors'][] = '����������� - ������� ' . $type;}

        return $prepared;
    }

    protected function prepareBalance ($params): bool
    {
        $prepared = false;

        $guid_param = ['guid' => $this->new_guid];
        $params = array_merge($params, $guid_param);

        $count1 = $this->db->getCount('PIKALKA.pasp_balance', $guid_param);
        if ($count1 > 0) {$prepared = true; return $prepared;}

        if ($count1 === 0) {
            $sql = file_get_contents($this->root . '/sql/pasport/insert/balance.sql');
            $this->db->runSQL($sql, $params);
        }

        if ($this->db->errors_count === 0) {$prepared = true;}
        else {$this->x['prepare_errors'][] = '������';}

        return $prepared;
    }

    protected function preparePributok ($params): bool
    {
        $prepared = false;

        $guid_param = ['guid' => $this->new_guid];
        $params = array_merge($params, $guid_param);

        $count1 = $this->db->getCount('PIKALKA.pasp_pributok', $guid_param);
        if ($count1 > 0) {$prepared = true; return $prepared;}

        if ($count1 === 0) {
            $sql = file_get_contents($this->root . '/sql/pasport/insert/pributok.sql');
            $this->db->runSQL($sql, $params);
        }

        if ($this->db->errors_count === 0) {$prepared = true;}
        else {$this->x['prepare_errors'][] = '��������';}

        return $prepared;
    }

    protected function prepareESV ($params): bool
    {
        $prepared = false;

        $guid_param = ['guid' => $this->new_guid];
        $params = array_merge($params, $guid_param);

        $count1 = $this->db->getCount('PIKALKA.pasp_esv', $guid_param);
        if ($count1 > 0) {$prepared = true; return $prepared;}

        if ($count1 === 0) {
            $sql = file_get_contents($this->root . '/sql/pasport/insert/esv.sql');
            $this->db->runSQL($sql, $params);
        }

        if ($this->db->errors_count === 0) {$prepared = true;}
        else {$this->x['prepare_errors'][] = '���';}

        return $prepared;
    }

    protected function preparePov ($params): bool
    {
        $prepared = false;

        $guid_param = ['guid' => $this->new_guid];
        $params = array_merge($params, $guid_param, ['c_distr' => $this->c_distr]);

        $sql = file_get_contents($this->root . '/sql/pasport/insert/pov.sql');
        $count1 = $this->db->runSQL($sql, $params);

        if ($count1 > 0) {
            $sql = file_get_contents($this->root . '/sql/pasport/insert/pov_t2.sql');
            $this->db->runSQL($sql, $params);
            $sql = file_get_contents($this->root . '/sql/pasport/insert/pov_t3.sql');
            $this->db->runSQL($sql, $params);
            $sql = file_get_contents($this->root . '/sql/pasport/insert/pov_t4.sql');
            $this->db->runSQL($sql, $params);
        }

        if ($this->db->errors_count === 0) {$prepared = true;}
        else {$this->x['prepare_errors'][] = '��������';}

        return $prepared;
    }

    protected function excelKontr ($sql, $params, $prefix): array
    {
        $array = $this->db->getAllFromSQL($sql, $params);
        $array = $this->transform1($array);

        if (empty($array)) {
            $fields = ['N', 'STI', 'TIN', 'NAME', 'OBS', 'PDV', 'NOM'];
            foreach ($fields as $value) {$array[$value] = [];}
        }

        $obs_sum = array_sum($array['OBS']);
        $pdv_sum = array_sum($array['PDV']);
        $array['VIDS'] = $this->vidsFromArray($array['OBS']);

        $kontr = $this->transform2($array, $prefix);
        $calculate_params = ['{'.$prefix.'OBS_SUM}' => $obs_sum, '{'.$prefix.'PDV_SUM}' => $pdv_sum];

        return array_merge($kontr, $calculate_params);
    }

    protected function transform1 (array $array = []): array
    {
        $result = [];
        if (empty($array)) {return $result;}
        $columns = array_keys($array[0]);

        foreach ($array as $row) {
            foreach ($columns as $col) {
                $value_utf8 = mb_convert_encoding($row[$col], 'utf-8', 'windows-1251');
                $result[$col][] = $value_utf8;
            }
        }
        return $result;
    }

    protected function transform2 (array $array = [], $prefix = ''): array
    {
        $result = [];
        if (empty($array)) {return $result;}

        foreach ($array as $key => $value) {$result['['.$prefix.$key.']'] = $value;}

        return $result;
    }

    protected function vidsFromArray (array $array = [], $precision = 0): array
    {
        $result = [];
        if (empty($array)) {return $result;}
        $sum = array_sum($array);

        foreach ($array as $row) {
            $result[] = round($row / $sum * 100, $precision);
        }
        return $result;
    }

    protected function getPost (): array
    {
        $post = [];
        $pattern = '#^[0-9]{6,10}$#';
        $post['tin'] = regex($pattern, $_POST['tin'], 0);

        $pattern = '#^\s*(3[01]|[12][0-9]|0?[1-9])\.(1[012]|0?[1-9])\.((?:19|20)\d{2})\s*$#';
        $post['dt1'] = regex($pattern, $_POST['dt1'], '01.01.2017');
        $post['dt2'] = regex($pattern, $_POST['dt2'], '31.12.2018');
        return $post;
    }

    protected function prepareParams ($params): array
    {
        $pattern = '#(\{[0-9a-zA-Z_.]+?\})|(\[\[[0-9a-zA-Z_.]+?\]\])|(\[[0-9a-zA-Z_.]+?\])#';
        $result = $new_array = [];

        foreach ($params as $param) {
            foreach ($param as $item) {
                if ($item !== null) {
                    preg_match_all($pattern, $item, $matches);
                    foreach ($matches[0] as $match) {$new_array[] = $match;}
                }
            }
        }
        foreach ($new_array as $item) {$result[$item] = '';}

        return $result;
    }
}
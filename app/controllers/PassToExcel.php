<?php

namespace App\controllers;

use alhimik1986\PhpExcelTemplator\PhpExcelTemplator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Helper;

class PassToExcel extends DBController
{
    protected $role = '22'; // ���� 22 - ������� ��������
    protected $ss; // SpreadSheet
    protected $guid;
    protected $c_distr;
    protected $default_params;
    protected $templateVars;
    protected $task;

    public function index(): void
    {
        /* �������� �� POST ��������� */
        $this->guid = Helper::checkRegEx('guid', $_POST['guid']);

        if ($this->guid === null) {
            $params = [
                'TIN' => Helper::checkRegEx('tin', $_POST['tin']),
                'DT1' => Helper::checkRegEx('date', $_POST['dt1']),
                'DT2' => Helper::checkRegEx('date', $_POST['dt2']),
                'TASKS' => Helper::checkRegEx('list', $_POST['task']),
                'GUID_USER' => $this->myUser->guid,
            ];
            $sql = getSQL('passport\get_task_ready_guid.sql');
            $task = $this->db->selectRaw($sql, $params)->pluck('TASK_ID', 'GUID');
        } else {
            $this->guid = Helper::checkRegEx('guid', $_POST['guid']);
            //$params = $this->db->getOneRow('PIKALKA.pass_jrn', ['guid' => $this->guid]);
            $params = $this->db->table('PIKALKA.pass_jrn')
                ->where('guid = :guid')->bind(['guid' => $this->guid])->first();

            $sql = getSQL('passport/get_tasks_guid.sql');
            $task = $this->db->selectRaw($sql, ['guid' => $this->guid])->pluck('TASK_ID', 'GUID');
        }

        /* ��������� Excel */
        $outputMethod = true;
        $templateFile = $this->root . '/xls/passport/template.xlsx';
        $outputFile = './passport.xlsx';
        $this->default_params = $this->templateVars = [];

        /* �������� ss - SpreadSheets */
        try {
            $this->ss = IOFactory::load($templateFile);
        } catch (\Exception $e) {
            echo $e->getMessage(); Exit;
        }

        /* ����������� ���� */
        if (isset($task[1])) {
            $this->toExcel_RegData(1, $params);
        }

        /* �������� */
        $this->toExcel_Related(2, $task);

        /* ����������� */
        $this->toExcel_Kontr(3, $task);

        /* ������ */
        if (isset($task[4])) {
            $array = $this->db->table('PIKALKA.pass_balance')->where('guid = :guid')
                ->orderBy('period_year, period_month')->bind(['guid' => $task[4]])->get();
            $this->setSheet(4, $this->transform($array));
        }

        if (isset($task[5])) {
            /* ��� */
            $array1 = $this->db->table('PIKALKA.pass_pdv')->where('guid = :guid')
                ->orderBy('period')->bind(['guid' => $task[5]])->get();
            $array1 = $this->addPrefix($array1, 'T1.');
            $t_array1 = $this->transform($array1);

            /* ��� в� */
            $array2 = $this->db->table('PIKALKA.pass_pdv_rik')->where('guid = :guid')
                ->orderBy('period_year')->bind(['guid' => $task[5]])->get();
            $array2 = $this->addPrefix($array2, 'T2.');
            $t_array2 = $this->transform($array2);

            $this->setSheet(5, array_merge($t_array1, $t_array2));
        }

        /* �������� */
        if (isset($task[6])) {
            $array = $this->db->table('PIKALKA.pass_pributok')->where('guid = :guid')
                ->orderBy('period_year, period_month')->bind(['guid' => $task[6]])->get();
            $this->setSheet(6, $this->transform($array));
        }

        /* ��� */
        if (isset($task[7])) {
            $array = $this->db->table('PIKALKA.pass_esv')->where('guid = :guid')
                ->orderBy('period')->bind(['guid' => $task[7]])->get();
            $this->setSheet(7, $this->transform($array));
        }

        /* ����������� */
        if (isset($task[8])) {
            $array = $this->db->table('PIKALKA.pass_povidom')->where('guid = :guid')
                ->orderBy('period_year')->bind(['guid' => $task[8]])->get();
            $this->setSheet(8, $this->transform($array));
        }

        /* 1-�� */
        if (isset($task[9])) {
            $sql = 'SELECT year, ozn_dox, cnt, ROUND(dox, 0) dox FROM DP00.t43_1df_ozn WHERE kod = :kod ORDER BY YEAR, ozn_dox';
            $array = $this->db->selectRaw($sql, ['kod' => $params['TIN']])->get();
            $this->setSheet(9, $this->transform($array));
        }

        /* ����� � pass_log */
        $params['TM'] = 0;
        unset($params['DT0']);
        $this->db->table('PIKALKA.pass_log')->insert($params);

        /* ��������� ������ ����� */
        for ($i = $this->ss->getSheetCount(); $i > 0; $i--) {
            if (!isset($task[$i])) {
                try {
                    $this->ss->removeSheetByIndex($i - 1);
                } catch (\Exception $e) {
                    //echo $e->getMessage();
                }
            }
        }

        if ($outputMethod) {
            PhpExcelTemplator::outputSpreadsheetToFile($this->ss, $outputFile);
        } else {
            PhpExcelTemplator::saveSpreadsheetToFile($this->ss, $outputFile);
        }

    }

    protected function toExcel_RegData($index, $params): void
    {
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $sheet = $this->ss->getSheet($index - 1);
        } catch (\Exception $e) {
            echo $e->getMessage(); Exit;
        }
        $reg_data = $this->excelRegData($params);
        $input_xlsParams = ['{tin}' => $params['TIN'], '{dt1}' => $params['DT1'], '{dt2}' => $params['DT2']];
        $data_from_oracle = array_merge($input_xlsParams, $reg_data);

        /** @noinspection PhpUndefinedMethodInspection */
        $templateCells = $sheet->toArray();
        $default_params = $this->getDefaultParams($templateCells);
        $templateParams = array_merge($default_params, $data_from_oracle);

        PhpExcelTemplator::renderWorksheet($sheet, $templateCells, $templateParams);
    }

    protected function toExcel_Related($index, $task): void
    {
        if (!isset($task[$index])) {return;}

        $sql = 'SELECT t.*, \'\' blank FROM PIKALKA.pass_pov_t1 t WHERE guid = :guid ORDER BY c_distr, tin, c_stan';
        $array1 = $this->db->selectRaw($sql, ['guid' => $task[2]])->get();
        $array1 = $this->transform($array1, 'T1.');

        $sql = 'SELECT c_distr, tin, ur_name, c_stan, post_name, typ, pin, NAME FROM pass_pov_t2 WHERE GUID = :GUID ORDER BY t DESC, tin, c_distr, c_stan, c_post';
        $array2 = $this->db->selectRaw($sql, ['guid' => $task[2]])->get();
        $array2 = $this->transform($array2, 'T2.');

        $array3 = $this->db->table('PIKALKA.pass_pov_t3')->where('guid = :guid')
            ->orderBy('t DESC, tin, c_distr, c_stan, c_post')->bind(['guid' => $task[2]])->get();
        $array3 = $this->transform($array3, 'T3.');

        $array4 = $this->db->table('PIKALKA.pass_pov_t4')->where('guid = :guid')
            ->orderBy('t DESC, tin, c_distr, c_stan, c_post')->bind(['guid' => $task[2]])->get();
        $array4 = $this->transform($array4, 'T4.');

        $array5 = $this->db->table('PIKALKA.pass_pov_t5')->where('guid = :guid')
            ->orderBy('t DESC, tin, c_distr, c_stan')->bind(['guid' => $task[2]])->get();
        $array5 = $this->transform($array5, 'T5.');

        $data_from_oracle = array_merge($array1, $array2, $array3, $array4, $array5);

        $this->setSheet($index, $data_from_oracle);
    }

    protected function toExcel_Kontr($index, $task): void
    {
        if (!isset($task[$index])) {return;}

        $sql = 'SELECT ROWNUM n, t.* FROM (SELECT * FROM PIKALKA.pass_kontr_kredit_3 WHERE guid = :guid ORDER BY obs DESC, tin) t';
        $array1 = $this->db->selectRaw($sql, ['guid' => $task[$index]])->get();
        $t_array = $this->transform($array1, 'T1.');
        $t_array = $this->addFieldPercent($t_array, '#T1.OBS#', '#T1.PERCENT#');
        $sum1 = $this->getSumFromArray($t_array, 'T1.OBS');
        $sum2 = $this->getSumFromArray($t_array, 'T1.PDV');
        $data_from_oracle1 = array_merge($t_array, $sum1, $sum2);

        $sql = 'SELECT ROWNUM n, t.* FROM (SELECT * FROM PIKALKA.pass_kontr_zobov_3 WHERE guid = :guid ORDER BY obs DESC, cp_tin) t';
        $array2 = $this->db->selectRaw($sql, ['guid' => $task[$index]])->get();
        $t_array = $this->transform($array2, 'T2.');
        $t_array = $this->addFieldPercent($t_array, '#T2.OBS#', '#T2.PERCENT#');
        $sum1 = $this->getSumFromArray($t_array, 'T2.OBS');
        $sum2 = $this->getSumFromArray($t_array, 'T2.PDV');
        $data_from_oracle2 = array_merge($t_array, $sum1, $sum2);

        $data_from_oracle = array_merge($data_from_oracle1, $data_from_oracle2);

        $this->setSheet($index, $data_from_oracle);
    }

    /* $t_array = $this->addFieldPercent($t_array, '#T1.OBS#', '#T1.PERCENT#'); */
    protected function addFieldPercent(array $array, $scan, $new_field, $precision = 0): array
    {
        $sum = array_sum($array[$scan]);
        $new_array[$new_field] = [];
        foreach ($array[$scan] as $item) {
            $new_array[$new_field][] = round($item / $sum * 100, $precision);
        }
        return array_merge($array, $new_array);
    }

    protected function addPrefix(array $array, $prefix): array
    {
        $result = [];
        foreach ($array as $row) {
            $new_row = [];
            foreach ($row as $key => $item) {
                $new_row[$prefix.$key] = $item;
            }
            $result[] = $new_row;
        }
        return $result;
    }

    protected function excelRegData($input_params): array
    {
        $tin = $params['TIN'] = $input_params['TIN'];

        $sql = 'SELECT PIKALKA.tax.get_dpi_by_tin(:tin) FROM dual';
        $dpi = $this->c_distr = $this->db->selectRaw($sql, $params)->getCell();
        $data = ['tin' => $tin, 'c_distr' => $dpi];
        $type_pl = (int) $this->db->table('RG02.r21taxpay')
            ->where('tin = :tin AND c_distr = :c_distr')->bind($data)->getCell('FACE_MODE');

        $data = ['tin' => $tin, 'c_distr' => $dpi];
        $r21taxpay = $this->db->table('RG02.r21taxpay')
            ->where('tin = :tin AND c_distr = :c_distr')->bind($data)->first();

        $data = ['c_stan' => $r21taxpay['C_STAN']];
        $stan_name = $this->db->table('ETALON.E_S_STAN')
            ->where('c_stan = :c_stan')->bind($data)->getCell('N_STAN');

        $data = ['kod' => $r21taxpay['KVED']];
        $kved_name = $this->db->table('ETALON.E_KVED')
             ->where('kod = :kod')->bind($data)->getCell('NU');

        $sql = getSQL('passport/get_address.sql');
        $address = $this->db->selectRaw($sql, ['tin' => $tin, 'c_distr' => $dpi])->getCell();

        if ($type_pl === 1) {
            $sql = 'SELECT c_post, pin, name, n_tel FROM RG02.r21manager WHERE tin = :tin';
            $array = $this->db->selectRaw($sql, $params)->get();
            $r21manager = Helper::array_combine2($array);
            $reg_params_ur = [
                '{r21manager.dir_pin}' => Helper::utf8($r21manager[1]['PIN']),
                '{r21manager.buh_pin}' => Helper::utf8($r21manager[2]['PIN']),
                '{r21manager.dir}' => Helper::utf8($r21manager[1]['NAME']),
                '{r21manager.buh}' => Helper::utf8($r21manager[2]['NAME']),
                '{r21manager.dir_tel}' => Helper::utf8($r21manager[1]['N_TEL']),
                '{r21manager.buh_tel}' => Helper::utf8($r21manager[2]['N_TEL']),
            ];
        } else {
            $reg_params_ur = [];
        }

        $sql = getSQL('passport/get_r21stan_h.sql');
        $array = $this->db->selectRaw($sql, ['tin' => $tin, 'c_distr' => $dpi])->get();
        $stan_h = $this->transform($array, 'SH.');

        $reg_params = [
            '{r21taxpay.c_distr}' => $this->c_distr,
            '{r21taxpay.name}' => Helper::utf8($r21taxpay['NAME']),
            '{r21taxpay.stan}' => $r21taxpay['C_STAN'],
            '{r21taxpay.stan_name}' => Helper::utf8($stan_name),
            '{r21taxpay.kved}' => $r21taxpay['KVED'],
            '{r21taxpay.kved_name}' => Helper::utf8($kved_name),
            '{r21taxpay.d_reg_sti}' => $r21taxpay['D_REG_STI'],
            '{r21paddr.address}' => Helper::utf8($address),
        ];

        $sql = 'SELECT * FROM AISR.pdv_act_r WHERE tin = :tin AND dat_anul IS NULL AND ROWNUM = 1';
        $pdv_act_r = $this->db->selectRaw($sql, $params)->first();
        if (!empty($pdv_act_r)) {$reg_params['{pdv_act_r.dat_reestr}'] = $pdv_act_r['DAT_REESTR'];}

        /* ���� �������� */
        $sql = getSQL('passport/kvedy.sql');
        $array = $this->db->selectRaw($sql, ['tin' => $params['TIN']])->get();
        $kvedy = $this->transform($array, 'KVED.');

        /* ���������� */
        $sql = getSQL('passport/founders.sql');
        $array = $this->db->selectRaw($sql, ['tin' => $params['TIN']])->get();
        $founders = $this->transform($array, 'FNDR.');

        /* ��� */
        $sql = getSQL('passport/rro.sql');
        $array = $this->db->selectRaw($sql, ['tin' => $params['TIN']])->get();
        $rro = $this->transform($array, 'RRO.');

        return array_merge($reg_params, $reg_params_ur, $stan_h, $kvedy, $founders, $rro);
    }

    protected function getDefaultParams($params): array
    {
        /* ������ ��� ������ � ����� ������ ������ {data} [data] [[data]] */
        $pattern = '@(\{[0-9a-zA-Z_.]+?\})|(\[\[#[0-9a-zA-Z_.]+?#\]\])|(\[#[0-9a-zA-Z_.]+?#\])@';
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

    /* $sum = $this->getSumFromArray($t_array, 'T1.PDV'); */
    protected function getSumFromArray(array $array, $find): array
    {
        $find_array = explode('.', $find);
        $prefix = $find_array[0] . '.';
        $field = $find_array[1];
        $sum_name = '{' . $prefix . $field . '_SUM}';
        $sum = array_sum($array['#' . $prefix . $field . '#']);
        return [$sum_name => $sum];
    }

    protected function setSheet($index, $array): void
    {
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $sheet = $this->ss->getSheet($index - 1);
            /** @noinspection PhpUndefinedMethodInspection */
            $templateCells = $sheet->toArray();
            $default_params = $this->getDefaultParams($templateCells);
            $templateParams = array_merge($default_params, $array);
            PhpExcelTemplator::renderWorksheet($sheet, $templateCells, $templateParams);
        } catch (\Exception $e) {
            echo $e->getMessage(); Exit;
        }
    }

    protected function transform(array $array, $prefix = ''): array
    {
        $result = [];
        if (empty($array)) {return $result;}

        $columns = array_keys($array[0]);

        foreach ($array as $row) {
            foreach ($columns as $col) {
                $value_utf8 = mb_convert_encoding($row[$col], 'utf-8', 'windows-1251');
                $result['#' . $prefix . $col . '#'][] = $value_utf8;
            }
        }
        return $result;
    }

}

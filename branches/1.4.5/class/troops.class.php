<?php
/**
 * @author Alex10336
 * Dernière modification: $Id$
 * @license GNU Public License 3.0 ( http://www.gnu.org/licenses/gpl-3.0.txt )
 * @license Creative Commons 3.0 BY-SA ( http://creativecommons.org/licenses/by-sa/3.0/deed.fr )
 */


class troops {
    static protected $instance;

    function AddBattle ($idate, $coords, $left, $right, $nb_assault, $pertes) {
        $carto = cartographie::getinstance();
        if (!$carto->FormatId($coords, $idsys, $iddet, 'troops::AddBattle')) return $carto->Erreurs();

        $sql = sprintf('SELECT `ID`, `nb_assault` FROM `SQL_PREFIX_troops_attack` WHERE '.
                '`coords_ss`=\'%s\' AND `coords_3p`=\'%s\' AND `when`=%d', $idsys, $iddet, $idate);
        $result = DataEngine::sql($sql);

        $line = array();
        if (mysql_num_rows($result)>0) {
            $line = mysql_fetch_assoc($result);
            if ($line['nb_assault'] >= $nb_assault)
                return 'Existe déjà ?';
        }

        if (!$planets = ownuniverse::getinstance()->get_coordswithname())
                return 'Information personnelles insuffisantes';

        $type='attacker';
        foreach ($planets as $v)
            if ($v['Coord'] == $coords) {
                $type  = 'defender';
                $tmp   = $right;
                $right = $left;
                $left  = $tmp;
                break;
            }

        if ($line['nb_assault']) {
            $sql = sprintf('UPDATE `SQL_PREFIX_troops_attack` SET '.
                    '`nb_assault`=%d, `players_defender`=\'%s\', `players_attack`=\'%s\', `players_pertes`=\'%s\' WHERE `ID`=%s',
                    $nb_assault, sqlesc($right), sqlesc($left), sqlesc($pertes), $line['ID'] );
		    $result = DataEngine::sql($sql);
		return 'Combat MAJ';
        } else {
            $sql = sprintf('INSERT INTO `SQL_PREFIX_troops_attack` '.
                    '(`type`, `nb_assault`,`when`, `coords_ss`, `coords_3p`, `players_defender`, `players_attack`, `players_pertes`)'.
                    ' VALUES (\'%s\', %d, %d, \'%s\', \'%s\', \'%s\', \'%s\', \'%s\')',
                    $type, $nb_assault, $idate, $idsys, $iddet, sqlesc($right), sqlesc($left), sqlesc($pertes) );
            $result = DataEngine::sql($sql);
		return 'Ajout ok';
        }
    }

    function AddPillage_log ($mode, $idate, $msg) {

        // check si existant
        $sql = sprintf('SELECT `pid` FROM SQL_PREFIX_troops_pillage WHERE Player=\'%s\' AND date=%d',
                sqlesc($_SESSION['_login']), $idate);
        $result = DataEngine::sql($sql);
        if (mysql_numrows($result)>0) return 'Log déjà ajouté';

        // Type du log/bataille
        // Puis recherche de la bataille (coords+participation)
        if ($mode=='defender') {
            preg_match('/Notre planète (.*) n\'est plus sous l\'occupation de (.*)\./', $msg, $info);
            $ident = 'Il a volé les ressources suivantes :';

            $planets = ownuniverse::getinstance()->get_coordswithname();

            foreach ($planets as $v)
                if ($v['Name'] == $info[1]) {
                    cartographie::getinstance()->FormatId($v['Coord'], $idsys, $iddet, 'troops::AddPillage_log(def,1)');
                    break;
                }

            $sql = sprintf('SELECT `ID` FROM `SQL_PREFIX_troops_attack` WHERE `type`=\'%s\' AND '.
                    '`coords_ss`=\'%s\' AND `coords_3p`=\'%s\' AND `when`<=%d AND `when`>=%d'.
                    ' AND `players_defender` LIKE \'%%"%s"%%\' AND `players_attack` LIKE \'%%"%s"%%\'',
                    sqlesc($mode), $idsys, $iddet, $idate, $idate-604800, sqlesc($_SESSION['_login']), sqlesc($info[2]));
            $result = DataEngine::sql($sql);
            if (mysql_numrows($result)<1) return 'Bataille introuvable ? (flutte)';
            if (mysql_numrows($result)>1) return 'Bataille multiple ? (omg)';
            $line = mysql_fetch_assoc($result);
            $mid = $line['ID'];
//            $pids = unserialize($line['pids']);
        } else {
            preg_match('/Nos troupes ont quitté la planète (.*) de (.*), l\'occupation est terminée\./', $msg, $info);
            $ident = 'Nous avons pillé les ressources suivantes :';

            $sql = sprintf('SELECT `POSIN`, `COORDET` FROM `SQL_PREFIX_Coordonnee` WHERE `type` in (0,3,5) AND '.
                    '`USER`=\'%s\' AND `INFOS`=\'%s\'',
                    $info[2], $info[1]);
            $result = DataEngine::sql($sql);
            if (mysql_numrows($result)<1) return 'Coordonnée du pillage introuvable ? (flutte)';
            if (mysql_numrows($result)>1) return 'Plusieurs coordonnée pour ce pillage ? (omg)';
            $line = mysql_fetch_assoc($result);
            $idsys = $line['POSIN'];
            $iddet = $line['COORDET'];

            $sql = sprintf('SELECT `ID` FROM `SQL_PREFIX_troops_attack` WHERE `type`=\'%s\' AND '.
                    '`coords_ss`=\'%s\' AND `coords_3p`=\'%s\' AND `when`<%d AND `when`>%d AND '.
                    '`players_attack` LIKE \'%%"%s"%%\' AND `players_defender` LIKE \'%%"%s"%%\'',
                    sqlesc($mode), $idsys, $iddet, $idate, $idate-604800, sqlesc($_SESSION['_login']), sqlesc($info[2]));
            $result = DataEngine::sql($sql);
            if (mysql_numrows($result)<1) return 'Bataille introuvable ? (flutte)';
            if (mysql_numrows($result)>1) return 'Bataille multiple ? (omg)';
            $line = mysql_fetch_assoc($result);
            $mid = $line['ID'];
            $pids = unserialize($line['pids']);
        }

        // Info à ajouter
        $amsg = explode("\n", trim(mb_substr($msg, mb_stripos($msg, $ident,0, 'utf8')+mb_strlen($ident, 'utf8'),-1, 'utf8')));
        $ares = DataEngine::a_ressources();
        $fields   = array();
        $sets     = array();
        $fields[] = 'date';
        $sets[]= $idate;
        $fields[] = 'mid';
        $sets[]= $mid;
        $fields[] = 'Player';
        $sets[]= '\''.sqlesc($_SESSION['_login']).'\'';
        foreach ($ares as $k => $v) {
            foreach ($amsg as $line) {
                list($key, $value) = explode(':', $line);
                if ($v['Nom'] == $key) {
                    $fields[] = 'ress'.$k;
                    $sets[]= DataEngine::strip_number($value);
                    ;
                    break;
                }
            }
        }
        $fields = implode(',',$fields);
        $sets = implode(',',$sets);

        $sql = 'INSERT INTO `SQL_PREFIX_troops_pillage` ('.$fields.') VALUES ('.$sets.')';
        $result = DataEngine::sql($sql);
//        $pid = mysql_insert_id();
//        array_push($pids, $pid);
//        $pids = sqlesc(serialize($pids));
//        DataEngine::sql('UPDATE SQL_PREFIX_troops_attack SET `pids`=\''.$pids.'\' WHERE ID='.$mid);

        return 'Pillage ajouté.';
    }

    /**
     * @return troops
     */
    static public function getinstance() {
        if ( ! self::$instance )
            self::$instance = new self();

        return self::$instance;
    }
}
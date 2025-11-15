<?php

class PacketPathRepository extends ModelRepository
{

    private static $_singletonInstance = null;

    public function __construct()
    {
        parent::__construct('PacketPath');
    }

    /**
     * Returnes an initiated PacketPathRepository
     *
     * @return PacketPathRepository
     */
    public static function getInstance()
    {
        if (self::$_singletonInstance === null) {
            self::$_singletonInstance = new PacketPathRepository();
        }

        return self::$_singletonInstance;
    }

    /**
     * Get object by id
     *
     * @param  int $id
     * @return PacketPath
     */
    public function getObjectById($id)
    {
        if (!isInt($packetId)) {
            return new PacketPath(0);
        }
        return $this->getObjectFromSql('select * from packet_path where id = ?', [$id]);
    }

    /**
     * Get object by packet id
     *
     * @param  int $id
     * @return PacketPath
     */
    public function getObjectListByPacketId($id)
    {
        if (!isInt($id)) {
            return [];
        }
        return $this->getObjectListFromSql('select * from packet_path where packet_id = ?', [$id]);
    }

    /**
     * Get packet path statistics for sending station
     *
     * @param  int $stationId
     * @param  int $minTimestamp
     * @return array
     */
    public function getSenderPacketPathSatistics($stationId, $minTimestamp = null)
    {
        if (!isInt($stationId)) {
            return [];
        }
        if ($minTimestamp == null || !isInt($minTimestamp)) {
            $minTimestamp = time() - (60*60*24*10); // Default to 10 days
        }
        $sql = 'select stats.station_id,
                       stats.number_of_packets,
                       stats.latest_timestamp,
                       stats.longest_distance,
                       (
                           select pp.latitude
                           from packet_path pp
                           where pp.sending_station_id = ?
                             and pp.station_id = stats.station_id
                             and pp.number = 0
                             and pp.timestamp > ?
                           order by pp.timestamp desc, pp.id desc
                           limit 1
                       ) latest_latitude,
                       (
                           select pp.longitude
                           from packet_path pp
                           where pp.sending_station_id = ?
                             and pp.station_id = stats.station_id
                             and pp.number = 0
                             and pp.timestamp > ?
                           order by pp.timestamp desc, pp.id desc
                           limit 1
                       ) latest_longitude,
                       (
                           select p.comment
                           from packet p
                           where p.station_id = stats.station_id
                             and p.comment is not null
                             and length(trim(p.comment)) > 0
                           order by p.timestamp desc, p.id desc
                           limit 1
                       ) latest_comment,
                       (
                           select p.timestamp
                           from packet p
                           where p.station_id = stats.station_id
                             and p.comment is not null
                             and length(trim(p.comment)) > 0
                           order by p.timestamp desc, p.id desc
                           limit 1
                       ) latest_comment_timestamp,
                       (
                           select p.comment
                           from packet p
                           where p.station_id = stats.station_id
                             and p.packet_type_id = 10
                             and p.comment is not null
                             and length(trim(p.comment)) > 0
                           order by p.timestamp desc, p.id desc
                           limit 1
                       ) latest_status,
                       (
                           select p.timestamp
                           from packet p
                           where p.station_id = stats.station_id
                             and p.packet_type_id = 10
                             and p.comment is not null
                             and length(trim(p.comment)) > 0
                           order by p.timestamp desc, p.id desc
                           limit 1
                       ) latest_status_timestamp
                from (
                    select station_id,
                           count(*) number_of_packets,
                           max(timestamp) latest_timestamp,
                           max(distance) longest_distance
                    from packet_path
                    where sending_station_id = ?
                      and timestamp > ?
                      and number = 0
                      and station_id != sending_station_id
                    group by station_id
                ) stats
                order by stats.latest_timestamp desc';
        $args = [$stationId, $minTimestamp, $stationId, $minTimestamp, $stationId, $minTimestamp];
        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get packet path statistics for receiving station
     *
     * @param  int $stationId
     * @param  int $minTimestamp
     * @return array
     */
    public function getReceiverPacketPathSatistics($stationId, $minTimestamp = null)
    {
        if (!isInt($stationId)) {
            return [];
        }
        if ($minTimestamp == null || !isInt($minTimestamp)) {
            $minTimestamp = time() - (60*60*24*10); // Default to 10 days
        }
        $sql = 'select stats.station_id,
                       stats.number_of_packets,
                       stats.latest_timestamp,
                       stats.longest_distance,
                       (
                           select pp.sending_latitude
                           from packet_path pp
                           where pp.station_id = ?
                             and pp.sending_station_id = stats.station_id
                             and pp.number = 0
                             and pp.timestamp > ?
                           order by pp.timestamp desc, pp.id desc
                           limit 1
                       ) latest_latitude,
                       (
                           select pp.sending_longitude
                           from packet_path pp
                           where pp.station_id = ?
                             and pp.sending_station_id = stats.station_id
                             and pp.number = 0
                             and pp.timestamp > ?
                           order by pp.timestamp desc, pp.id desc
                           limit 1
                       ) latest_longitude,
                       (
                           select p.comment
                           from packet p
                           where p.station_id = stats.station_id
                             and p.comment is not null
                             and length(trim(p.comment)) > 0
                           order by p.timestamp desc, p.id desc
                           limit 1
                       ) latest_comment,
                       (
                           select p.timestamp
                           from packet p
                           where p.station_id = stats.station_id
                             and p.comment is not null
                             and length(trim(p.comment)) > 0
                           order by p.timestamp desc, p.id desc
                           limit 1
                       ) latest_comment_timestamp,
                       (
                           select p.comment
                           from packet p
                           where p.station_id = stats.station_id
                             and p.packet_type_id = 10
                             and p.comment is not null
                             and length(trim(p.comment)) > 0
                           order by p.timestamp desc, p.id desc
                           limit 1
                       ) latest_status,
                       (
                           select p.timestamp
                           from packet p
                           where p.station_id = stats.station_id
                             and p.packet_type_id = 10
                             and p.comment is not null
                             and length(trim(p.comment)) > 0
                           order by p.timestamp desc, p.id desc
                           limit 1
                       ) latest_status_timestamp
                from (
                    select sending_station_id station_id,
                           count(*) number_of_packets,
                           max(timestamp) latest_timestamp,
                           max(distance) longest_distance
                    from packet_path
                    where station_id = ?
                      and timestamp > ?
                      and number = 0
                      and station_id != sending_station_id
                    group by sending_station_id
                ) stats
                order by stats.latest_timestamp desc';
        $args = [$stationId, $minTimestamp, $stationId, $minTimestamp, $stationId, $minTimestamp];
        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get latest data list by receiving station id
     *
     * @param  int     $stationId
     * @param  int     $hours
     * @param  int     $limit
     * @return array
     */
    public function getLatestDataListByReceivingStationId($stationId, $hours, $limit)
    {
        if (!isInt($stationId) || !isInt($hours)) {
            return [];
        }
        $minTimestamp = time() - (60*60*$hours);

        $sql = 'select pp.*
            from packet_path pp
            where pp.station_id = ?
                and pp.timestamp >= ?
                and pp.number = 0
                and pp.sending_latitude is not null
                and pp.sending_longitude is not null
            order by pp.timestamp
            limit ?';

        $arg = [$stationId, $minTimestamp, $limit];

        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $arg);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get latest data list by receiving station id (will try to exclude stationary sending stations)
     *
     * @param  int     $stationId
     * @param  int     $hours
     * @param  int     $limit
     * @return array
     */
    public function getLatestMovingDataListByReceivingStationId($stationId, $hours, $limit)
    {
        if (!isInt($stationId) || !isInt($hours)) {
            return [];
        }
        $minTimestamp = time() - (60*60*$hours);

        $sql = 'select pp.*
            from packet_path pp
                join station s on s.id = pp.sending_station_id
            where pp.station_id = ?
                and pp.timestamp >= ?
                and pp.number = 0
                and pp.sending_latitude is not null
                and pp.sending_longitude is not null
                and pp.sending_latitude != s.latest_confirmed_latitude
                and pp.sending_longitude != s.latest_confirmed_longitude
            order by pp.timestamp
            limit ?';

        $arg = [$stationId, $minTimestamp, $limit];

        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $arg);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

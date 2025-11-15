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
        $sql = 'select station_id, count(*) number_of_packets, max(timestamp) latest_timestamp, max(distance) longest_distance from packet_path where sending_station_id = ? and timestamp > ? and number = 0 and station_id != sending_station_id group by station_id order by max(timestamp) desc';
        $args = [$stationId, $minTimestamp];
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
        $sql = 'select sending_station_id station_id, count(*) number_of_packets, max(timestamp) latest_timestamp, max(distance) longest_distance from packet_path where station_id = ? and timestamp > ? and number = 0 and station_id != sending_station_id group by sending_station_id order by max(timestamp) desc';
        $args = [$stationId, $minTimestamp];
        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get packet path statistics (with metadata) for stations that received packets from the specified station
     *
     * @param  int $stationId
     * @param  int $minTimestamp
     * @param  int $limit
     * @return array
     */
    public function getSenderPacketPathSatisticsWithDetails($stationId, $minTimestamp = null, $limit = 10)
    {
        if (!isInt($stationId)) {
            return [];
        }

        if ($minTimestamp === null || !isInt($minTimestamp)) {
            $minTimestamp = time() - (60 * 60 * 24 * 10);
        }

        if (!isInt($limit) || $limit <= 0) {
            $limit = 10;
        }

        $limit = min($limit, 50);

        $sql = 'select station_id,
                       count(*) number_of_packets,
                       max(timestamp) latest_timestamp,
                       max(distance) longest_distance
                from packet_path
                where sending_station_id = ?
                  and timestamp > ?
                  and number = 0
                  and station_id != sending_station_id
                group by station_id
                order by max(timestamp) desc
                limit ' . $limit;

        $args = [$stationId, $minTimestamp];

        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $args);
        $statsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($statsRows) === 0) {
            return [];
        }

        $stationIds = [];
        foreach ($statsRows as $row) {
            if (isset($row['station_id']) && isInt($row['station_id'])) {
                $stationIds[] = (int)$row['station_id'];
            }
        }

        if (count($stationIds) === 0) {
            return [];
        }

        $packetRepository = PacketRepository::getInstance();
        $latestComments = $packetRepository->getLatestCommentPacketsForStationIds($stationIds);
        $latestStatuses = $packetRepository->getLatestStatusPacketsForStationIds($stationIds);
        $latestCoordinates = $this->getLatestCoordinatesForSenderStation($stationId, $stationIds, $minTimestamp);

        $rows = [];
        foreach ($statsRows as $row) {
            $stationIdValue = isset($row['station_id']) && isInt($row['station_id']) ? (int)$row['station_id'] : null;
            if ($stationIdValue === null) {
                continue;
            }

            $rowData = $row;

            if (isset($latestComments[$stationIdValue])) {
                $rowData['latest_comment'] = $latestComments[$stationIdValue]['comment'];
                $rowData['latest_comment_timestamp'] = $latestComments[$stationIdValue]['timestamp'];
            }

            if (isset($latestStatuses[$stationIdValue])) {
                $rowData['latest_status'] = $latestStatuses[$stationIdValue]['comment'];
                $rowData['latest_status_timestamp'] = $latestStatuses[$stationIdValue]['timestamp'];
            }

            if (isset($latestCoordinates[$stationIdValue])) {
                $rowData['latitude'] = $latestCoordinates[$stationIdValue]['latitude'];
                $rowData['longitude'] = $latestCoordinates[$stationIdValue]['longitude'];
            }

            $rows[] = $rowData;
        }

        return $this->formatCommunicationStats($rows);
    }

    /**
     * Get packet path statistics (with metadata) for stations that sent packets to the specified station
     *
     * @param  int $stationId
     * @param  int $minTimestamp
     * @param  int $limit
     * @return array
     */
    public function getReceiverPacketPathSatisticsWithDetails($stationId, $minTimestamp = null, $limit = 10)
    {
        if (!isInt($stationId)) {
            return [];
        }

        if ($minTimestamp === null || !isInt($minTimestamp)) {
            $minTimestamp = time() - (60 * 60 * 24 * 10);
        }

        if (!isInt($limit) || $limit <= 0) {
            $limit = 10;
        }

        $limit = min($limit, 50);

        $sql = 'select sending_station_id station_id,
                       count(*) number_of_packets,
                       max(timestamp) latest_timestamp,
                       max(distance) longest_distance
                from packet_path
                where station_id = ?
                  and timestamp > ?
                  and number = 0
                  and station_id != sending_station_id
                group by sending_station_id
                order by max(timestamp) desc
                limit ' . $limit;

        $args = [$stationId, $minTimestamp];

        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $args);
        $statsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($statsRows) === 0) {
            return [];
        }

        $stationIds = [];
        foreach ($statsRows as $row) {
            if (isset($row['station_id']) && isInt($row['station_id'])) {
                $stationIds[] = (int)$row['station_id'];
            }
        }

        if (count($stationIds) === 0) {
            return [];
        }

        $packetRepository = PacketRepository::getInstance();
        $latestComments = $packetRepository->getLatestCommentPacketsForStationIds($stationIds);
        $latestStatuses = $packetRepository->getLatestStatusPacketsForStationIds($stationIds);
        $latestCoordinates = $this->getLatestCoordinatesForReceiverStation($stationId, $stationIds, $minTimestamp);

        $rows = [];
        foreach ($statsRows as $row) {
            $stationIdValue = isset($row['station_id']) && isInt($row['station_id']) ? (int)$row['station_id'] : null;
            if ($stationIdValue === null) {
                continue;
            }

            $rowData = $row;

            if (isset($latestComments[$stationIdValue])) {
                $rowData['latest_comment'] = $latestComments[$stationIdValue]['comment'];
                $rowData['latest_comment_timestamp'] = $latestComments[$stationIdValue]['timestamp'];
            }

            if (isset($latestStatuses[$stationIdValue])) {
                $rowData['latest_status'] = $latestStatuses[$stationIdValue]['comment'];
                $rowData['latest_status_timestamp'] = $latestStatuses[$stationIdValue]['timestamp'];
            }

            if (isset($latestCoordinates[$stationIdValue])) {
                $rowData['latitude'] = $latestCoordinates[$stationIdValue]['latitude'];
                $rowData['longitude'] = $latestCoordinates[$stationIdValue]['longitude'];
            }

            $rows[] = $rowData;
        }

        return $this->formatCommunicationStats($rows);
    }

    /**
     * Get latest coordinate data for stations that received packets from the specified station
     *
     * @param  int   $stationId
     * @param  array $receiverStationIds
     * @param  int   $minTimestamp
     * @return array
     */
    public function getLatestCoordinatesForSenderStation($stationId, array $receiverStationIds, $minTimestamp = null)
    {
        if (!isInt($stationId) || count($receiverStationIds) === 0) {
            return [];
        }

        $stationIds = [];
        foreach ($receiverStationIds as $receiverStationId) {
            if (isInt($receiverStationId)) {
                $stationIds[] = (int)$receiverStationId;
            }
        }

        if (count($stationIds) === 0) {
            return [];
        }

        if ($minTimestamp === null || !isInt($minTimestamp)) {
            $minTimestamp = time() - (60 * 60 * 24 * 10);
        }

        $placeholders = implode(',', array_fill(0, count($stationIds), '?'));
        $args = array_merge([$stationId], $stationIds, [$minTimestamp]);

        $sql = 'select pp.station_id,
                       pp.latitude,
                       pp.longitude
                from packet_path pp
                where pp.sending_station_id = ?
                  and pp.station_id in (' . $placeholders . ')
                  and pp.number = 0
                  and pp.timestamp > ?
                order by pp.station_id, pp.timestamp desc, pp.id desc';

        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $coordinates = [];
        foreach ($rows as $row) {
            $relatedStationId = (int)$row['station_id'];
            if (!isset($coordinates[$relatedStationId])) {
                $coordinates[$relatedStationId] = [
                    'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                    'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                ];
            }
        }

        return $coordinates;
    }

    /**
     * Get latest coordinate data for stations that sent packets to the specified station
     *
     * @param  int   $stationId
     * @param  array $senderStationIds
     * @param  int   $minTimestamp
     * @return array
     */
    public function getLatestCoordinatesForReceiverStation($stationId, array $senderStationIds, $minTimestamp = null)
    {
        if (!isInt($stationId) || count($senderStationIds) === 0) {
            return [];
        }

        $stationIds = [];
        foreach ($senderStationIds as $senderStationId) {
            if (isInt($senderStationId)) {
                $stationIds[] = (int)$senderStationId;
            }
        }

        if (count($stationIds) === 0) {
            return [];
        }

        if ($minTimestamp === null || !isInt($minTimestamp)) {
            $minTimestamp = time() - (60 * 60 * 24 * 10);
        }

        $placeholders = implode(',', array_fill(0, count($stationIds), '?'));
        $args = array_merge([$stationId], $stationIds, [$minTimestamp]);

        $sql = 'select pp.sending_station_id station_id,
                       pp.sending_latitude latitude,
                       pp.sending_longitude longitude
                from packet_path pp
                where pp.station_id = ?
                  and pp.sending_station_id in (' . $placeholders . ')
                  and pp.number = 0
                  and pp.timestamp > ?
                order by pp.sending_station_id, pp.timestamp desc, pp.id desc';

        $pdo = PDOConnection::getInstance();
        $stmt = $pdo->prepareAndExec($sql, $args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $coordinates = [];
        foreach ($rows as $row) {
            $relatedStationId = (int)$row['station_id'];
            if (!isset($coordinates[$relatedStationId])) {
                $coordinates[$relatedStationId] = [
                    'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                    'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                ];
            }
        }

        return $coordinates;
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

    private function formatCommunicationStats(array $rows)
    {
        $formatted = [];

        foreach ($rows as $row) {
            $stationId = isset($row['station_id']) ? (int)$row['station_id'] : null;

            if ($stationId === null) {
                continue;
            }

            $latestComment = null;
            if (isset($row['latest_comment'])) {
                $commentValue = trim((string)$row['latest_comment']);
                if ($commentValue !== '') {
                    $latestComment = $commentValue;
                }
            }

            $latestStatus = null;
            if (isset($row['latest_status'])) {
                $statusValue = trim((string)$row['latest_status']);
                if ($statusValue !== '') {
                    $latestStatus = $statusValue;
                }
            }

            $formatted[] = [
                'station_id' => $stationId,
                'number_of_packets' => isset($row['number_of_packets']) ? (int)$row['number_of_packets'] : 0,
                'latest_timestamp' => isset($row['latest_timestamp']) && is_numeric($row['latest_timestamp']) ? (int)$row['latest_timestamp'] : null,
                'longest_distance' => isset($row['longest_distance']) && is_numeric($row['longest_distance']) ? (float)$row['longest_distance'] : null,
                'latest_comment' => $latestComment,
                'latest_comment_timestamp' => isset($row['latest_comment_timestamp']) && is_numeric($row['latest_comment_timestamp']) ? (int)$row['latest_comment_timestamp'] : null,
                'latest_status' => $latestStatus,
                'latest_status_timestamp' => isset($row['latest_status_timestamp']) && is_numeric($row['latest_status_timestamp']) ? (int)$row['latest_status_timestamp'] : null,
                'latitude' => isset($row['latitude']) && $row['latitude'] !== null ? (float)$row['latitude'] : null,
                'longitude' => isset($row['longitude']) && $row['longitude'] !== null ? (float)$row['longitude'] : null,
            ];
        }

        return $formatted;
    }
}

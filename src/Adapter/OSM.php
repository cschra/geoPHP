<?php

/*
 * @author Báthory Péter <peter.bathory@cartographia.hu>
 * @since 2016-02-27
 *
 * This code is open-source and licenced under the Modified BSD License.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace geoPHP\Adapter;

use geoPHP\Geometry\Collection;
use geoPHP\Geometry\Geometry;
use geoPHP\Geometry\GeometryCollection;
use geoPHP\Geometry\Point;
use geoPHP\Geometry\MultiPoint;
use geoPHP\Geometry\LineString;
use geoPHP\Geometry\MultiLineString;
use geoPHP\Geometry\Polygon;
use geoPHP\Geometry\MultiPolygon;

/**
 * PHP Geometry <-> OpenStreetMap XML encoder/decoder
 *
 * This adapter is not ready yet. It lacks a relation writer, and the reader has problems with invalid multipolygons
 * Since geoPHP doesn't support metadata, it cannot read and write OSM tags.
 */
class OSM implements GeoAdapter
{

    const OSM_COORDINATE_PRECISION = '%.7f';
    const OSM_API_URL = 'http://openstreetmap.org/api/0.6/';

    /**
     * @var \DOMDocument
     */
    protected $xmlObj;
    
    /**
     * @var array<mixed>
     */
    protected $nodes = [];
    
    /**
     * @var array<int, array>
     */
    protected $ways = [];
    
    /**
     * @var int
     */
    protected $idCounter = 0;

    /**
     * Read OpenStreetMap XML string into geometry objects
     *
     * @param string $osm An OSM XML string
     * @return Geometry|GeometryCollection
     * @throws \Exception
     */
    public function read(string $osm): Geometry
    {
        // Load into DOMDocument
        $xmlobj = new \DOMDocument();
        
        if ($xmlobj->loadXML($osm) === false) {
            throw new \Exception("Invalid OSM XML: " . substr($osm, 0, 100));
        }

        $this->xmlObj = $xmlobj;
        try {
            $geom = $this->geomFromXML();
        } catch (\Exception $e) {
            throw new \Exception("Cannot read geometries from OSM XML: " . $e->getMessage());
        }

        return $geom;
    }
    
    /**
     *
     * @return array<array>|array{} nodes
     */
    private function parseNodes(): array
    {
        $nodes = [];
        $nodeId = 0;
        foreach ($this->xmlObj->getElementsByTagName('node') as $node) {
            $lat = null;
            $lon = null;
            
            /** @var \DOMElement $node */
            if ($node->attributes !== null) {
                $lat = $node->attributes->getNamedItem('lat')->nodeValue;
                $lon = $node->attributes->getNamedItem('lon')->nodeValue;
                $nodeId = intval($node->attributes->getNamedItem('id')->nodeValue);
            } else {
                ++$nodeId;
            }
            
            $tags = [];
            foreach ($node->getElementsByTagName('tag') as $tag) {
                $key = $tag->attributes->getNamedItem('k')->nodeValue;
                if ($key === 'source' || $key === 'fixme' || $key === 'created_by') {
                    continue;
                }
                $tags[$key] = $tag->attributes->getNamedItem('v')->nodeValue;
            }
            $nodes[$nodeId] = [
                'point' => new Point($lon, $lat),
                'assigned' => false,
                'tags' => $tags
            ];
        }
        
        return $nodes;
    }

    /**
     *
     * @param array<array> $nodes
     * @return array<array>|array{} ways
     */
    private function parseWays(array &$nodes): array
    {
        $ways = [];
        $wayId = 0;
        foreach ($this->xmlObj->getElementsByTagName('way') as $way) {
            /** @var \DOMElement $way */
            if ($way->attributes !== null) {
                $wayId = intval($way->attributes->getNamedItem('id')->nodeValue);
            } else {
                ++$wayId;
            }
            $wayNodes = [];
            foreach ($way->getElementsByTagName('nd') as $node) {
                if ($node->attributes === null) {
                    continue;
                }
                $ref = intval($node->attributes->getNamedItem('ref')->nodeValue);
                if (isset($nodes[$ref])) {
                    $nodes[$ref]['assigned'] = true;
                    $wayNodes[] = $ref;
                }
            }
            $tags = [];
            foreach ($way->getElementsByTagName('tag') as $tag) {
                $key = $tag->attributes->getNamedItem('k')->nodeValue;
                if ($key === 'source' || $key === 'fixme' || $key === 'created_by') {
                    continue;
                }
                $tags[$key] = $tag->attributes->getNamedItem('v')->nodeValue;
            }
            if (count($wayNodes) >= 2) {
                $ways[$wayId] = [
                    'nodes' => $wayNodes,
                    'assigned' => false,
                    'tags' => $tags,
                    'isRing' => ($wayNodes[0] === $wayNodes[count($wayNodes) - 1])
                ];
            }
        }
        
        return $ways;
    }
    
    /**
     *
     * @param array<array> $nodes
     * @param array<array> $ways
     * @return Geometry[]|array{} geometries
     */
    private function parseRelations(array &$nodes, array &$ways): array
    {
        $geometries = [];
        
        /** @var \DOMElement $relation */
        foreach ($this->xmlObj->getElementsByTagName('relation') as $relation) {
            // Collect relation members
            list($relationPoints, $relationLines, $relationPolygons) = $this->parseRelationMembers($relation, $nodes, $ways);

            // Assemble relation geometries
            $geometryCollection = [];
            if (!empty($relationPolygons)) {
                $geometryCollection[] = count($relationPolygons) === 1 ? $relationPolygons[0] : new MultiPolygon($relationPolygons);
            }
            if (!empty($relationLines)) {
                $geometryCollection[] = count($relationLines) === 1 ? $relationLines[0] : new MultiLineString($relationLines);
            }
            if (!empty($relationPoints)) {
                $geometryCollection[] = count($relationPoints) === 1 ? $relationPoints[0] : new MultiPoint($relationPoints);
            }

            if (!empty($geometryCollection)) {
                $geometries[] = count($geometryCollection) === 1 ? $geometryCollection[0] : new GeometryCollection($geometryCollection);
            }
        }
        
        return $geometries;
    }
    
    /**
     *
     * @staticvar array<string> $polygonalTypes
     * @staticvar array<string> $linearTypes
     * @param \DOMElement $relation
     * @param array<array> $nodes
     * @param array<array> $ways
     * @return array<array>
     */
    private function parseRelationMembers(\DOMElement $relation, array &$nodes, array &$ways): array
    {
        /** @var Point[] $relationPoints */
        $relationPoints = [];
        
        /** @var array[] $relationWays */
        $relationWays = [];
        
        /** @var Polygon[] $relationPolygons */
        $relationPolygons = [];
        
        /** @var LineString[] $relationLines */
        $relationLines = [];
        
        // walk the members
        foreach ($relation->getElementsByTagName('member') as $member) {
            $memberType = $member->attributes->getNamedItem('type')->nodeValue;
            $ref = $member->attributes->getNamedItem('ref')->nodeValue;

            if ($memberType === 'node' && isset($nodes[$ref])) {
                $nodes[$ref]['assigned'] = true;
                $relationPoints[] = $nodes[$ref]['point'];
            }
            if ($memberType === 'way' && isset($ways[$ref])) {
                $ways[$ref]['assigned'] = true;
                $relationWays[$ref] = $ways[$ref]['nodes'];
            }
        }
        
        $relationType = $this->getRelationType($relation);
        
        // add polygons
        static $polygonalTypes = ['multipolygon', 'boundary'];
        if (in_array($relationType, $polygonalTypes)) {
            $relationPolygons = $this->processMultipolygon($relationWays, $nodes);
        }

        // add lines
        static $linearTypes = ['route', 'waterway'];
        if (in_array($relationType, $linearTypes)) {
            $relationLines = $this->processRoutes($relationWays, $nodes);
        }
            
        return [$relationPoints, $relationLines, $relationPolygons];
    }
    
    /**
     *
     * @param \DOMElement $relation
     * @return string|null
     */
    private function getRelationType(\DOMElement $relation)
    {
        foreach ($relation->getElementsByTagName('tag') as $tag) {
            if ($tag->attributes->getNamedItem('k')->nodeValue === 'type') {
                return $tag->attributes->getNamedItem('v')->nodeValue;
            }
        }
        
        return null;
    }
    
    /**
     * @staticvar array $polygonalTypes
     * @staticvar array $linearTypes
     * @return Geometry
     */
    protected function geomFromXML(): Geometry
    {
        // Processing OSM Nodes
        $nodes = $this->parseNodes();
        if (empty($nodes)) {
            return new GeometryCollection();
        }

        // Processing OSM Ways
        $ways = $this->parseWays($nodes);

        // Processing OSM Relations
        $geometries = $this->parseRelations($nodes, $ways);

        // add way-geometries
        foreach ($ways as $way) {
            if ((!$way['assigned'] || !empty($way['tags'])) &&
                    !isset($way['tags']['boundary']) &&
                    (!isset($way['tags']['natural']) || $way['tags']['natural'] !== 'mountain_range')
            ) {
                $linePoints = [];
                foreach ($way['nodes'] as $wayNode) {
                    $linePoints[] = $nodes[$wayNode]['point'];
                }
                $line = new LineString($linePoints);
                if ($way['isRing']) {
                    $polygon = new Polygon([$line]);
                    if ($polygon->isSimple()) {
                        $geometries[] = $polygon;
                    } else {
                        $geometries[] = $line;
                    }
                } else {
                    $geometries[] = $line;
                }
            }
        }

        // add node-geometries
        foreach ($nodes as $node) {
            if (!$node['assigned'] || !empty($node['tags'])) {
                $geometries[] = $node['point'];
            }
        }

        return count($geometries) === 1 ? $geometries[0] : new GeometryCollection($geometries);
    }

    /**
     * @param array<array> $relationWays
     * @param array<array> $nodes
     * @return LineString[] $lineStrings
     */
    protected function processRoutes(array &$relationWays, array $nodes): array
    {
        // Construct lines
        /** @var LineString[] $lineStrings */
        $lineStrings = [];
        while (count($relationWays) > 0) {
            $line = array_shift($relationWays);
            if ($line[0] !== $line[count($line) - 1]) {
                do {
                    $waysAdded = 0;
                    foreach ($relationWays as $id => $wayNodes) {
                        // Last node of ring = first node of way => put way to the end of ring
                        if ($line[count($line) - 1] === $wayNodes[0]) {
                            $line = array_merge($line, array_slice($wayNodes, 1));
                            unset($relationWays[$id]);
                            ++$waysAdded;
                            // Last node of ring = last node of way => reverse way and put to the end of ring
                        } elseif ($line[count($line) - 1] === $wayNodes[count($wayNodes) - 1]) {
                            $line = array_merge($line, array_slice(array_reverse($wayNodes), 1));
                            unset($relationWays[$id]);
                            ++$waysAdded;
                            // First node of ring = last node of way => put way to the beginning of ring
                        } elseif ($line[0] === $wayNodes[count($wayNodes) - 1]) {
                            $line = array_merge(array_slice($wayNodes, 0, count($wayNodes) - 1), $line);
                            unset($relationWays[$id]);
                            ++$waysAdded;
                            // First node of ring = first node of way => reverse way and put to the beginning of ring
                        } elseif ($line[0] === $wayNodes[0]) {
                            $line = array_merge(array_reverse(array_slice($wayNodes, 1)), $line);
                            unset($relationWays[$id]);
                            ++$waysAdded;
                        }
                    }
                    // If line members are not ordered, we need to repeat end matching some times
                } while ($waysAdded > 0);
            }

            // Create the new LineString
            $linePoints = [];
            foreach ($line as $lineNode) {
                $linePoints[] = $nodes[$lineNode]['point'];
            }
            $lineStrings[] = new LineString($linePoints);
        }

        return $lineStrings;
    }

    /**
     * @param array<array> $relationWays
     * @param array<array> $nodes
     * @return Polygon[] $relationPolygons
     */
    protected function processMultipolygon(array &$relationWays, array $nodes): array
    {
        // create polygons
        $rings = $this->constructRings($relationWays, $nodes);
        
        // Calculate containment
        $containment = array_fill(0, count($rings), array_fill(0, count($rings), false));
        foreach ($rings as $i => $ring) {
            foreach ($rings as $j => $ring2) {
                if ($i !== $j && $ring->contains($ring2)) {
                    $containment[$i][$j] = true;
                }
            }
        }
        $containmentCount = count($containment);

        /*
          print '&nbsp; &nbsp;';
          for($i=0; $i<count($rings); $i++) {
          print $rings[$i]->getNumberOfPoints() . ' ';
          }
          print "<br>";
          for($i=0; $i<count($rings); $i++) {
          print $rings[$i]->getNumberOfPoints() . ' ';
          for($j=0; $j<count($rings); $j++) {
          print ($containment[$i][$j] ? '1' : '0') . ' ';
          }
          print "<br>";
        }*/

        // Group rings (outers and inners)

        /** @var boolean[] $found */
        $found = array_fill(0, $containmentCount, false);
        $foundCount = 0;
        $round = 0;
        /** @var int[][] $polygonsRingIds */
        $polygonsRingIds = [];
        /** @var Polygon[] $relationPolygons */
        $relationPolygons = [];
        while ($foundCount < $containmentCount && $round < 100) {
            $ringsFound = [];
            for ($i = 0; $i < $containmentCount; $i++) {
                if ($found[$i]) {
                    continue;
                }
                $containCount = 0;
                $numCurContainment = count($containment[$i]);
                for ($j = 0; $j < $numCurContainment; $j++) {
                    if (!$found[$j]) {
                        $containCount += $containment[$j][$i];
                    }
                }
                if ($containCount === 0) {
                    $ringsFound[] = $i;
                }
            }
            if ($round % 2 === 0) {
                $polygonsRingIds = [];
            }
            foreach ($ringsFound as $ringId) {
                $found[$ringId] = true;
                ++$foundCount;
                if ($round % 2 === 1) {
                    foreach ($polygonsRingIds as $outerId => $polygon) {
                        if ($containment[$outerId][$ringId]) {
                            $polygonsRingIds[$outerId][] = $ringId;
                        }
                    }
                } else {
                    $polygonsRingIds[$ringId] = [0 => $ringId];
                }
            }
            if ($round % 2 === 1 || $foundCount === $containmentCount) {
                foreach ($polygonsRingIds as $k => $ringGroup) {
                    $linearRings = [];
                    foreach ($ringGroup as $polygonRing) {
                        $linearRings[] = $rings[$polygonRing]->exteriorRing();
                    }
                    $relationPolygons[] = new Polygon($linearRings);
                }
            }
            ++$round;
        }

        return $relationPolygons;
    }
    
    /**
     * @TODO: what to do with broken rings?
     * I propose to force-close if start -> end point distance is less then 10% of line length, otherwise drop it.
     * But if dropped, its inner ring will be outers, which is not good.
     * We should save the role for each ring (outer, inner, mixed) during ring creation and check it during ring grouping
     *
     * @param array<array> $relationWays
     * @param array<array> $nodes
     * @return Polygon[]
     */
    private function constructRings(array &$relationWays, array $nodes): array
    {
        /** @var Polygon[] $rings */
        $rings = [];
        while (!empty($relationWays)) {
            $ring = array_shift($relationWays);
            if ($ring[0] !== $ring[count($ring) - 1]) {
                do {
                    $waysAdded = 0;
                    foreach ($relationWays as $id => $wayNodes) {
                        // Last node of ring = first node of way => put way to the end of ring
                        if ($ring[count($ring) - 1] === $wayNodes[0]) {
                            $ring = array_merge($ring, array_slice($wayNodes, 1));
                            unset($relationWays[$id]);
                            ++$waysAdded;
                            // Last node of ring = last node of way => reverse way and put to the end of ring
                        } elseif ($ring[count($ring) - 1] === $wayNodes[count($wayNodes) - 1]) {
                            $ring = array_merge($ring, array_slice(array_reverse($wayNodes), 1));
                            unset($relationWays[$id]);
                            ++$waysAdded;
                            // First node of ring = last node of way => put way to the beginning of ring
                        } elseif ($ring[0] === $wayNodes[count($wayNodes) - 1]) {
                            $ring = array_merge(array_slice($wayNodes, 0, count($wayNodes) - 1), $ring);
                            unset($relationWays[$id]);
                            ++$waysAdded;
                            // First node of ring = first node of way => reverse way and put to the beginning of ring
                        } elseif ($ring[0] === $wayNodes[0]) {
                            $ring = array_merge(array_reverse(array_slice($wayNodes, 1)), $ring);
                            unset($relationWays[$id]);
                            ++$waysAdded;
                        }
                    }
                    // If ring members are not ordered, we need to repeat end matching some times
                } while ($waysAdded > 0 && $ring[0] !== $ring[count($ring) - 1]);
            }

            // Create the new Polygon
            if ($ring[0] === $ring[count($ring) - 1]) {
                $ringPoints = [];
                foreach ($ring as $ringNode) {
                    $ringPoints[] = $nodes[$ringNode]['point'];
                }
                $newPolygon = new Polygon([new LineString($ringPoints)]);
                if ($newPolygon->isSimple()) {
                    $rings[] = $newPolygon;
                }
            }
        }
        
        return $rings;
    }

    /**
     * @param Geometry $geometry
     * @return string
     */
    public function write(Geometry $geometry): string
    {
        $this->processGeometry($geometry);

        $osm = "<?xml version='1.0' encoding='UTF-8'?>\n<osm version='0.6' upload='false' generator='geoPHP'>\n";
        foreach ($this->nodes as $latlon => $node) {
            $latlon = explode('_', $latlon);
            $osm .= "  <node id='{$node['id']}' visible='true' lat='$latlon[0]' lon='$latlon[1]' />\n";
        }
        foreach ($this->ways as $wayId => $way) {
            $osm .= "  <way id='{$wayId}' visible='true'>\n";
            foreach ($way as $nodeId) {
                $osm .= "    <nd ref='{$nodeId}' />\n";
            }
            $osm .= "  </way>\n";
        }

        $osm .= "</osm>";
        
        return $osm;
    }

    /**
     * @param Geometry $geometry
     * @return void
     */
    protected function processGeometry($geometry)
    {
        if (!$geometry->isEmpty()) {
            switch ($geometry->geometryType()) {
                case Geometry::POINT:
                    /** @var Point $geometry */
                    $this->processPoint($geometry);
                    break;
                case Geometry::LINESTRING:
                    /** @var LineString $geometry */
                    $this->processLineString($geometry);
                    break;
                case Geometry::POLYGON:
                    /** @var Polygon $geometry */
                    $this->processPolygon($geometry);
                    break;
                case Geometry::MULTI_POINT:
                case Geometry::MULTI_LINESTRING:
                case Geometry::MULTI_POLYGON:
                case Geometry::GEOMETRY_COLLECTION:
                    /** @var Collection $geometry */
                    $this->processCollection($geometry);
                    break;
            }
        }
    }

    /**
     * @param Point $point
     * @param bool|false $isWayPoint
     * @return int
     */
    protected function processPoint($point, $isWayPoint = false)
    {
        $nodePosition = sprintf(self::OSM_COORDINATE_PRECISION . '_' . self::OSM_COORDINATE_PRECISION, $point->getY(), $point->getX());
        if (!isset($this->nodes[$nodePosition])) {
            $this->nodes[$nodePosition] = ['id' => --$this->idCounter, "used" => $isWayPoint];
            return $this->idCounter;
        } else {
            if ($isWayPoint) {
                $this->nodes[$nodePosition]['used'] = true;
            }
            return $this->nodes[$nodePosition]['id'];
        }
    }

    /**
     * @param LineString $line
     * @return void
     */
    protected function processLineString($line)
    {
        $nodes = [];
        foreach ($line->getPoints() as $point) {
            $nodes[] = $this->processPoint($point, true);
        }
        $this->ways[--$this->idCounter] = $nodes;
    }

    /**
     * @param Polygon $polygon
     * @return void
     */
    protected function processPolygon($polygon)
    {
        // TODO: Support interior rings
        $this->processLineString($polygon->exteriorRing());
    }

    /**
     * @param Collection $collection
     * @return void
     */
    protected function processCollection($collection)
    {
        // TODO: multi geometries should be converted to relations
        foreach ($collection->getComponents() as $component) {
            $this->processGeometry($component);
        }
    }

    /**
     *
     * @param int|float $left
     * @param int|float $bottom
     * @param int|float $right
     * @param int|float $top
     * @return string|false
     * @throws \Exception
     */
    public static function downloadFromOSMByBbox($left, $bottom, $right, $top)
    {
        /** @noinspection PhpUnusedParameterInspection */
        set_error_handler(
            function ($errNO, $errStr, $errFile, $errLine, $errContext) {
                if (isset($errContext['http_response_header'])) {
                    foreach ($errContext['http_response_header'] as $line) {
                        if (strpos($line, 'Error: ') > -1) {
                              throw new \Exception($line);
                        }
                    }
                }
                throw new \Exception('unknown error');
            },
            E_WARNING
        );

        try {
            $osmFile = file_get_contents(self::OSM_API_URL . "map?bbox={$left},{$bottom},{$right},{$top}");
            restore_error_handler();
            return $osmFile;
        } catch (\Exception $e) {
            restore_error_handler();
            throw new \Exception("Failed to download from OSM. " . $e->getMessage());
        }
    }
}

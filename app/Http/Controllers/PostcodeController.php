<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchByLocationRequest;
use App\Http\Requests\SearchByTextRequest;
use App\Models\Postcode;
use http\Client\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PHPCoord\CoordinateReferenceSystem\Geographic2D;
use PHPCoord\CoordinateReferenceSystem\Projected;
use PHPCoord\Point\GeographicPoint;
use PHPCoord\Point\ProjectedPoint;
use PHPCoord\UnitOfMeasure\Angle\Degree;
use PHPCoord\UnitOfMeasure\Length\Metre;

class PostcodeController extends Controller
{
    // Radius of kilometers searchByLocation will use to find nearby locations.
    // This could easily be a passed in parameter, but don't want to load too much data
    const LOCATION_RADIUS = 0.5;

    /**
     * Searches postcodes based on passed search term, minimum of 2 letters.
     *
     * @param SearchByTextRequest $request
     * @return JsonResponse
     */
    public function searchByText(SearchByTextRequest $request): JsonResponse
    {
        // Sanitise the postcode to remove any spaces
        $search = str_replace(' ', '', $request->get('text'));

        $postcodes = Postcode::where('postcode', 'LIKE', '%' . $search . '%')
            ->paginate(20);

        return response()->json([
            'searchTerm' => $search,
            'postcodes' => $postcodes,
        ]);
    }

    /**
     * Takes latitude & longitude and finds all postcodes within a specific radius
     *
     * @param SearchByLocationRequest $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \PHPCoord\Exception\UnknownCoordinateReferenceSystemException
     */
    public function searchByLocation(SearchByLocationRequest $request): JsonResponse
    {
        $latitude = $request->get('latitude');
        $longitude = $request->get('longitude');

        list($easting, $northing) = $this->convertToEastingNorthing($latitude, $longitude);
        // Lets get a rough list of bits that might be nearby
        $postcodes = $this->getNearbyPostcodes($easting, $northing);

        // And now lets refine our results, no point spending processor power on a more accurate
        // initial search across millions of results
        $withinRadius = $this->getPostcodesWithinRadius($postcodes, $easting, $northing);

        return response()->json([
            'searchRaidus' => self::LOCATION_RADIUS,
            'count' => count($withinRadius),
            'results' => $withinRadius,
        ]);
    }

    /**
     * Convert latitude/longitude to UK Easting/Northing
     *
     * @param float $latitude
     * @param float $longitude
     * @return array
     * @throws \PHPCoord\Exception\UnknownCoordinateReferenceSystemException
     */
    protected function convertToEastingNorthing(float $latitude, float $longitude): array
    {
        // Create a coordinate class using latitude/longitude
        $coordinates = GeographicPoint::create(
            Geographic2D::fromSRID(Geographic2D::EPSG_WGS_84),
            new Degree($latitude),
            new Degree($longitude)
        );
        // Convert those coordinates to Ordnance Survey standards to find the easting/northing values
        $ukRefSystem = Projected::fromSRID(Projected::EPSG_OSGB36_BRITISH_NATIONAL_GRID);
        $ukCoordinates = $coordinates->convert($ukRefSystem);

        return [
            (int) $ukCoordinates->getEasting()->getValue(),
            (int) $ukCoordinates->getNorthing()->getValue(),
        ];
    }

    /**
     * Search database for nearby postcodes, this is designed to be a rough search to improve accuracy later
     *
     * @param int $easting
     * @param int $northing
     * @return Collection
     */
    protected function getNearbyPostcodes(int $easting, int $northing) : Collection
    {
        // Get radius in meters
        $radius = self::LOCATION_RADIUS * 1000;
        $lowerEastingBoundry = $easting - $radius;
        $higherEastingBoundry = $easting + $radius;
        $lowerNorthingBoundry = $northing - $radius;
        $higherNorthingBoundry = $northing + $radius;

        return Postcode::where('eastings', '>=', $lowerEastingBoundry)
            ->where('eastings', '<=', $higherEastingBoundry)
            ->where('northings', '>=', $lowerNorthingBoundry)
            ->where('northings', '<=', $higherNorthingBoundry)
            ->get();
    }

    /**
     * Given a set of nearby postcodes find which are within a specific difference
     * of a set of coordinates. The specific range is a class property of this class
     *
     * @param Collection $postcodes
     * @param $easting
     * @param $northing
     * @return array
     * @throws \PHPCoord\Exception\UnknownCoordinateReferenceSystemException
     */
    protected function getPostcodesWithinRadius(Collection $postcodes, $easting, $northing): array
    {
        // We are converting stuff between easting/northing and lat/lng coordinate systems in this function
        // these classes are used to tell phpCooord what types of coordinates we want.
        $ukRefSystem = Projected::fromSRID(Projected::EPSG_OSGB36_BRITISH_NATIONAL_GRID);
        $latLngRefSystem = Geographic2D::fromSRID(Geographic2D::EPSG_WGS_84);

        // Create the original point ready to compare and figure out distances with other points
        $origin = ProjectedPoint::createFromEastingNorthing(
            $ukRefSystem,
            new Metre($easting),
            new Metre($northing)
        );

        $withinRadius = [];
        foreach ($postcodes as $postcode) {
            // Create the new point so we can see how far from the origin
            $newPoint = ProjectedPoint::createFromEastingNorthing(
                $ukRefSystem,
                new Metre($postcode->eastings),
                new Metre($postcode->northings)
            );

            // Distance in meters
            $distance = $origin->calculateDistance($newPoint)
                ->getValue();
            // Change distance to km and round to 3 decimal places
            $distance = number_format($distance / 1000, 3);

            if ($distance < self::LOCATION_RADIUS) {
                // Assuming this data is going to be used by a mapping system, latitude/longitude
                // will be more useful than easting/northing, so lets convert this data again.
                $newPoint = $newPoint->convert($latLngRefSystem);
                $withinRadius[] = [
                    'postcode' => $postcode->postcode,
                    'distance' => $distance,
                    'latitude' => round($newPoint->getLatitude()->getValue(), 6),
                    'longitude' => round($newPoint->getLongitude()->getValue(), 6),
                ];
            }
        }

        return $withinRadius;
    }
}

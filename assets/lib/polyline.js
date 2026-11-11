/**
 * Decodes an encoded polyline (Google format, precision 5, as returned by OSRM).
 * Returns a list of [latitude, longitude] pairs. Invalid or empty input gives [].
 */
export function decodePolyline(encoded, precision = 5) {
    if (typeof encoded !== 'string' || encoded === '') {
        return [];
    }
    const factor = 10 ** precision;
    const points = [];
    let index = 0;
    let lat = 0;
    let lng = 0;

    const readValue = () => {
        let result = 0;
        let shift = 0;
        let byte;
        do {
            if (index >= encoded.length) {
                throw new RangeError('Truncated polyline');
            }
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);
        return result & 1 ? ~(result >> 1) : result >> 1;
    };

    try {
        while (index < encoded.length) {
            lat += readValue();
            lng += readValue();
            points.push([lat / factor, lng / factor]);
        }
    } catch (error) {
        return [];
    }

    return points;
}

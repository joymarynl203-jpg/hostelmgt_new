/**

 * Hostel map pins. Requires Google Maps JS API (callback: hmsHostelMapPickersBoot).

 * New pickers: no marker until the admin clicks the map (coordinates stay empty until then),

 * or uses "My location" to drop a pin from the device GPS.

 */

(function () {

    'use strict';



    var defaultCenter = { lat: 0.3476, lng: 32.5825 };



    function parseInitial(latId, lngId) {

        var latIn = document.getElementById(latId);

        var lngIn = document.getElementById(lngId);

        if (!latIn || !lngIn) {

            return null;

        }

        var lat = parseFloat(String(latIn.value || '').trim());

        var lng = parseFloat(String(lngIn.value || '').trim());

        if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {

            return { lat: lat, lng: lng };

        }

        return null;

    }



    function bindPicker(el) {

        if (!el || el.getAttribute('data-hms-map-ready') === '1') {

            return;

        }

        var latId = el.getAttribute('data-lat-target');

        var lngId = el.getAttribute('data-lng-target');

        if (!latId || !lngId) {

            return;

        }

        var latIn = document.getElementById(latId);

        var lngIn = document.getElementById(lngId);

        if (!latIn || !lngIn) {

            return;

        }



        var initial = parseInitial(latId, lngId);

        var mapCenter = initial || defaultCenter;



        var map = new google.maps.Map(el, {

            center: mapCenter,

            zoom: initial ? 16 : 12,

            mapTypeControl: true,

            streetViewControl: true,

        });



        var marker = null;



        function sync() {

            if (!marker) {

                return;

            }

            var p = marker.getPosition();

            if (!p) {

                return;

            }

            latIn.value = p.lat().toFixed(7);

            lngIn.value = p.lng().toFixed(7);

        }



        function placeOrMoveMarker(latLng) {

            if (!marker) {

                marker = new google.maps.Marker({

                    position: latLng,

                    map: map,

                    draggable: true,

                });

                marker.addListener('dragend', sync);

            } else {

                marker.setPosition(latLng);

            }

            sync();

        }



        if (initial) {

            placeOrMoveMarker(new google.maps.LatLng(initial.lat, initial.lng));

        }



        map.addListener('click', function (ev) {

            if (!ev.latLng) {

                return;

            }

            placeOrMoveMarker(ev.latLng);

        });



        var panel = document.createElement('div');

        panel.style.margin = '10px';

        panel.style.padding = '8px 10px';

        panel.style.background = 'rgba(255,255,255,0.95)';

        panel.style.borderRadius = '6px';

        panel.style.boxShadow = '0 1px 4px rgba(0,0,0,0.2)';

        panel.style.maxWidth = '220px';



        var locBtn = document.createElement('button');

        locBtn.type = 'button';

        locBtn.className = 'btn btn-sm btn-primary w-100';

        locBtn.setAttribute('aria-label', 'Use my current location for the map pin');

        locBtn.textContent = 'Use my current location';



        var errEl = document.createElement('div');

        errEl.style.display = 'none';

        errEl.style.fontSize = '12px';

        errEl.style.color = '#b02a37';

        errEl.style.marginTop = '8px';

        errEl.style.lineHeight = '1.35';



        var errTimer = null;

        function showGeoError(msg) {

            errEl.textContent = msg;

            errEl.style.display = 'block';

            if (errTimer) {

                clearTimeout(errTimer);

            }

            errTimer = setTimeout(function () {

                errEl.style.display = 'none';

                errTimer = null;

            }, 8000);

        }



        locBtn.addEventListener('click', function () {

            if (!navigator.geolocation) {

                showGeoError('Your browser does not support geolocation.');

                return;

            }

            locBtn.disabled = true;

            navigator.geolocation.getCurrentPosition(

                function (pos) {

                    locBtn.disabled = false;

                    var lat = pos.coords.latitude;

                    var lng = pos.coords.longitude;

                    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {

                        showGeoError('Received invalid coordinates from the device.');

                        return;

                    }

                    var ll = new google.maps.LatLng(lat, lng);

                    placeOrMoveMarker(ll);

                    map.panTo(ll);

                    map.setZoom(Math.max(map.getZoom(), 16));

                },

                function (err) {

                    locBtn.disabled = false;

                    var code = err && err.code;

                    if (code === 1) {

                        showGeoError('Location was blocked. Allow location access for this site in your browser settings, then try again.');

                    } else if (code === 2) {

                        showGeoError('Position unavailable. Try again or set the pin by clicking the map.');

                    } else if (code === 3) {

                        showGeoError('Location request timed out. Try again or click the map to place the pin.');

                    } else {

                        showGeoError('Could not read your location. Click the map to place the pin instead.');

                    }

                },

                { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }

            );

        });



        panel.appendChild(locBtn);

        panel.appendChild(errEl);

        map.controls[google.maps.ControlPosition.TOP_RIGHT].push(panel);



        el.setAttribute('data-hms-map-ready', '1');

    }



    window.hmsHostelMapPickersBoot = function () {

        document.querySelectorAll('[data-hms-map-picker="1"]').forEach(function (el) {

            if (!el.closest('.collapse')) {

                bindPicker(el);

            }

        });

        document.querySelectorAll('.collapse').forEach(function (col) {

            col.addEventListener('shown.bs.collapse', function () {

                col.querySelectorAll('[data-hms-map-picker="1"]').forEach(bindPicker);

            });

        });

    };

})();



/* Cascading building, floor and room pickers.

   Any element pair marked data-loc-building / data-loc-floor /
   data-loc-room wires itself up: choosing a building loads its floors,
   choosing a floor narrows the rooms, and the room list carries the room
   code so the server never has to guess which room was meant.

   A building with no mapped rooms falls back to a plain text box, because
   field stations have no floor plans and people still need to file. */
(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function LocationGroup(root) {
        this.building = root.querySelector('[data-loc-building]');
        this.floor = root.querySelector('[data-loc-floor]');
        this.room = root.querySelector('[data-loc-room]');
        this.code = root.querySelector('[data-loc-code]');
        this.note = root.querySelector('[data-loc-note]');
        this.rooms = [];
        if (!this.building || !this.room) { return; }

        var self = this;
        this.building.addEventListener('change', function () { self.load(); });
        if (this.floor) {
            this.floor.addEventListener('change', function () { self.renderRooms(); });
        }
        this.room.addEventListener('change', function () { self.syncCode(); });
        if (this.building.value) { this.load(this.room.getAttribute('data-initial')); }
    }

    /** The building number, which may sit on the option rather than in its value. */
    LocationGroup.prototype.buildingNumber = function () {
        if (this.building.tagName === 'SELECT') {
            var opt = this.building.options[this.building.selectedIndex];
            return opt ? (opt.getAttribute('data-bldg') || opt.value) : '';
        }
        return this.building.value;
    };

    LocationGroup.prototype.load = function (initial) {
        var self = this;
        var b = this.buildingNumber();
        this.setNote('');
        if (!b) { this.reset(); return; }
        fetch('api/rooms.php?bldg=' + encodeURIComponent(b))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                self.rooms = d.rooms || [];
                if (!self.rooms.length) {
                    self.freeText();
                    return;
                }
                if (self.floor) {
                    self.floor.innerHTML = '<option value="">All floors</option>'
                        + (d.floors || []).map(function (f) {
                            return '<option value="' + esc(f) + '">Floor ' + esc(f) + '</option>';
                        }).join('');
                    self.floor.disabled = false;
                }
                self.renderRooms(initial);
            })
            .catch(function () { self.freeText(); });
    };

    LocationGroup.prototype.renderRooms = function (initial) {
        var f = this.floor ? this.floor.value : '';
        var list = this.rooms.filter(function (r) { return !f || r.floor === f; });
        var current = initial || this.room.value;
        this.room.innerHTML = '<option value="">--Select Room--</option>'
            + list.map(function (r) {
                var label = r.room + (r.name ? ' — ' + r.name : '');
                if (!f) { label = 'Floor ' + r.floor + ' · ' + label; }
                return '<option value="' + esc(r.room) + '" data-code="' + esc(r.code)
                     + '" data-floor="' + esc(r.floor) + '"'
                     + (r.room === current ? ' selected' : '') + '>' + esc(label) + '</option>';
            }).join('');
        this.room.removeAttribute('data-freetext');
        this.syncCode();
    };

    LocationGroup.prototype.freeText = function () {
        // No mapped rooms: keep it usable rather than blocking the person.
        var name = this.room.getAttribute('name');
        var value = this.room.value || '';
        var input = document.createElement('input');
        input.setAttribute('name', name);
        input.setAttribute('data-loc-room', '');
        input.setAttribute('data-freetext', '1');
        input.value = value;
        input.placeholder = 'room number';
        this.room.parentNode.replaceChild(input, this.room);
        this.room = input;
        if (this.floor) { this.floor.disabled = false; }
        if (this.code) { this.code.value = ''; }
        this.setNote('This building has no floor plan on file, so type the room or a description.');
    };

    LocationGroup.prototype.reset = function () {
        if (this.floor) {
            this.floor.innerHTML = '<option value="">--</option>';
            this.floor.disabled = true;
        }
        if (this.room.tagName === 'SELECT') {
            this.room.innerHTML = '<option value="">--Select a building first--</option>';
        }
        if (this.code) { this.code.value = ''; }
    };

    LocationGroup.prototype.syncCode = function () {
        if (!this.code || this.room.tagName !== 'SELECT') { return; }
        var opt = this.room.options[this.room.selectedIndex];
        this.code.value = opt ? (opt.getAttribute('data-code') || '') : '';
        if (opt && this.floor && opt.getAttribute('data-floor') && !this.floor.value) {
            this.floor.value = opt.getAttribute('data-floor');
        }
        // Anything listening for a room change, such as the move item
        // picker, hears about it here.
        this.code.dispatchEvent(new CustomEvent('roomchange', {
            bubbles: true,
            detail: { code: this.code.value, room: this.room.value }
        }));
    };

    LocationGroup.prototype.setNote = function (text) {
        if (this.note) {
            this.note.textContent = text;
            this.note.hidden = !text;
        }
    };

    window.LocationGroup = LocationGroup;

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-loc-group]').forEach(function (g) {
            new LocationGroup(g);
        });
    });
    if (document.readyState !== 'loading') {
        document.querySelectorAll('[data-loc-group]').forEach(function (g) {
            if (!g.__wired) { g.__wired = true; new LocationGroup(g); }
        });
    }
}());

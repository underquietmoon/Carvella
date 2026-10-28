document.addEventListener('DOMContentLoaded', function() {
    const pickupDate = document.getElementById('pickup_date');
    const returnDate = document.getElementById('return_date');

    if (pickupDate && returnDate) {
        pickupDate.addEventListener('change', function() {
            // Same-day rentals are allowed, so the floor is the pickup date
            // itself (not the day after). Values are already YYYY-MM-DD, so no
            // Date maths - and therefore no timezone drift - is needed.
            returnDate.min = this.value;
            if (returnDate.value && returnDate.value < this.value) {
                returnDate.value = this.value;
            }
        });
    }

    const reservationForm = document.getElementById('reservation-form');
    if (reservationForm) {
        reservationForm.addEventListener('submit', function(e) {
            if (!confirm('Confirm this reservation?')) {
                e.preventDefault();
            }
        });
    }
});

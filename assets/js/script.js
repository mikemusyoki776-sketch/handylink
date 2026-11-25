document.addEventListener("DOMContentLoaded", function () {

    console.log("HandyLink JavaScript loaded successfully.");

    const deleteForms = document.querySelectorAll(".delete-service-form");

    deleteForms.forEach(function (form) {

        form.addEventListener("submit", function (event) {

            const confirmed = confirm(
                "Are you sure you want to delete this service?"
            );

            if (!confirmed) {
                event.preventDefault();
            }

        });

    });

});

document.addEventListener("DOMContentLoaded", function () {

    console.log("HandyLink JavaScript loaded successfully.");

    const deleteForms = document.querySelectorAll(".delete-service-form");

    deleteForms.forEach(function (form) {

        form.addEventListener("submit", function (event) {

            const confirmed = confirm(
                "Are you sure you want to delete this service?"
            );

            if (!confirmed) {
                event.preventDefault();
            }

        });

    });


    const availabilityForm = document.querySelector("#availability-form");

    if (availabilityForm) {

        availabilityForm.addEventListener("submit", function (event) {

            const startTime = document.querySelector("#start_time").value;
            const endTime = document.querySelector("#end_time").value;

            if (startTime >= endTime) {

                event.preventDefault();

                alert("End time must be later than start time.");

            }

        });

    }

});
/* =========================================================
   OrTra Suisse de l'Événementiel — main.js
   Nav mobile, toggle champ entreprise, soumission AJAX des
   formulaires (inscription / contact), anti-spam basique.
   ========================================================= */
(function () {
  "use strict";

  /* ---------- Navigation mobile ---------- */
  var navToggle = document.querySelector("[data-nav-toggle]");
  var mainNav = document.querySelector("[data-main-nav]");
  if (navToggle && mainNav) {
    navToggle.addEventListener("click", function () {
      var isOpen = mainNav.classList.toggle("is-open");
      navToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });
  }

  /* ---------- Anti-spam : horodatage de chargement du formulaire ---------- */
  document.querySelectorAll("[data-form-started-at]").forEach(function (field) {
    field.value = Date.now().toString();
  });

  /* ---------- Toggle champ "entreprise" selon type de membre ---------- */
  var membreRadios = document.querySelectorAll('input[name="type_membre"]');
  var entrepriseField = document.querySelector("[data-entreprise-field]");
  if (membreRadios.length && entrepriseField) {
    var updateEntrepriseVisibility = function () {
      var checked = document.querySelector('input[name="type_membre"]:checked');
      var show = checked && (checked.value === "organisateur" || checked.value === "prestataire");
      entrepriseField.style.display = show ? "" : "none";
    };
    membreRadios.forEach(function (radio) {
      radio.addEventListener("change", updateEntrepriseVisibility);
    });
    updateEntrepriseVisibility();
  }

  /* ---------- Validation & soumission AJAX ---------- */
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function setFieldError(form, fieldName, message) {
    var field = form.querySelector('[name="' + fieldName + '"]');
    if (!field) return;
    var wrapper = field.closest(".field");
    if (!wrapper) return;
    wrapper.classList.add("has-error");
    var errorEl = wrapper.querySelector(".field-error");
    if (errorEl) errorEl.textContent = message;
  }

  function clearFieldErrors(form) {
    form.querySelectorAll(".field.has-error").forEach(function (wrapper) {
      wrapper.classList.remove("has-error");
    });
  }

  function showAlert(form, type, message) {
    var alertEl = form.querySelector("[data-form-alert]");
    if (!alertEl) return;
    alertEl.className = "form-alert is-visible form-alert--" + type;
    alertEl.textContent = message;
    alertEl.setAttribute("role", type === "error" ? "alert" : "status");
    alertEl.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function validateAjaxForm(form) {
    var valid = true;
    clearFieldErrors(form);

    form.querySelectorAll("[required]").forEach(function (field) {
      if (field.type === "radio") return;
      if (field.type === "checkbox" && !field.checked) {
        setFieldError(form, field.name, "Ce champ est obligatoire.");
        valid = false;
        return;
      }
      if (field.type !== "checkbox" && !field.value.trim()) {
        setFieldError(form, field.name, "Ce champ est obligatoire.");
        valid = false;
      }
    });

    var radioGroup = form.querySelector('input[name="type_membre"]');
    if (radioGroup) {
      var checked = form.querySelector('input[name="type_membre"]:checked');
      if (!checked) {
        showAlert(form, "error", "Veuillez sélectionner un type de membre.");
        valid = false;
      }
    }

    var emailField = form.querySelector('input[type="email"]');
    if (emailField && emailField.value.trim() && !EMAIL_RE.test(emailField.value.trim())) {
      setFieldError(form, emailField.name, "Format d'e-mail invalide.");
      valid = false;
    }

    return valid;
  }

  function handleAjaxForm(form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();

      if (!validateAjaxForm(form)) {
        if (!form.querySelector("[data-form-alert]").classList.contains("is-visible")) {
          showAlert(form, "error", "Merci de corriger les champs indiqués ci-dessous.");
        }
        return;
      }

      var submitBtn = form.querySelector('[type="submit"]');
      var originalLabel = submitBtn ? submitBtn.textContent : "";
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Envoi en cours...";
      }

      var formData = new FormData(form);

      fetch(form.action, {
        method: "POST",
        body: formData,
        headers: { "X-Requested-With": "XMLHttpRequest" },
      })
        .then(function (response) {
          return response.json().catch(function () {
            throw new Error("reponse-invalide");
          });
        })
        .then(function (data) {
          if (data.success) {
            showAlert(form, "success", data.message || "Merci, votre demande a bien été enregistrée.");
            form.reset();
            var updateFn = form.querySelector('input[name="type_membre"]');
            if (updateFn) {
              var evt = new Event("change");
              form.querySelectorAll('input[name="type_membre"]').forEach(function (r) {
                r.dispatchEvent(evt);
              });
            }
          } else {
            if (data.errors) {
              Object.keys(data.errors).forEach(function (fieldName) {
                setFieldError(form, fieldName, data.errors[fieldName]);
              });
            }
            showAlert(form, "error", data.message || "Une erreur est survenue, merci de vérifier le formulaire.");
          }
        })
        .catch(function () {
          showAlert(
            form,
            "error",
            "Impossible d'envoyer le formulaire pour le moment. Merci de réessayer dans quelques instants ou de nous contacter directement par téléphone."
          );
        })
        .finally(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
          }
        });
    });
  }

  document.querySelectorAll("[data-ajax-form]").forEach(handleAjaxForm);
})();

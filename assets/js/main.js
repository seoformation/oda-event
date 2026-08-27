/* =========================================================
   OrTra Suisse de l'Événementiel — main.js
   Nav mobile, toggle des champs entreprise / compte event-swiss.com,
   soumission AJAX des formulaires (inscription / contact), anti-spam
   basique.
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

  /* ---------- Formulaire d'adhésion : privé/entreprise + compte event-swiss.com ---------- */
  var accountTypeRadios = document.querySelectorAll("[data-account-type]");
  var companyFieldsBlocks = document.querySelectorAll("[data-event-swiss-company-fields]");
  var profileTypeChoiceRadios = document.querySelectorAll("[data-profile-type-choice]");
  var profileTypeOutput = document.querySelector("[data-profile-type-output]");
  var prenomField = document.getElementById("prenom");
  var nomField = document.getElementById("nom");
  var legalNameField = document.getElementById("legal_name");
  var entrepriseField = document.getElementById("entreprise");
  var companyAddressField = document.getElementById("company_address");

  var contactEmailField = document.querySelector("[data-contact-email]");
  var eventSwissEmailDisplay = document.querySelector("[data-event-swiss-email-display]");

  if (accountTypeRadios.length) {
    var updateProfileTypeOutput = function () {
      var checkedAccountType = document.querySelector("[data-account-type]:checked");
      if (!checkedAccountType) {
        profileTypeOutput.value = "";
      } else if (checkedAccountType.value === "private") {
        profileTypeOutput.value = "talent";
      } else {
        var checkedProfile = document.querySelector("[data-profile-type-choice]:checked");
        profileTypeOutput.value = checkedProfile ? checkedProfile.value : "";
      }
    };

    var updateAccountTypeVisibility = function () {
      var checkedAccountType = document.querySelector("[data-account-type]:checked");
      var isCompany = !!checkedAccountType && checkedAccountType.value === "company";
      companyFieldsBlocks.forEach(function (block) {
        block.style.display = isCompany ? "" : "none";
      });
      profileTypeChoiceRadios.forEach(function (radio) {
        if (isCompany) radio.setAttribute("required", "required");
        else radio.removeAttribute("required");
      });
      if (legalNameField) legalNameField.required = isCompany;
      if (entrepriseField) entrepriseField.required = isCompany;
      if (companyAddressField) companyAddressField.required = isCompany;
      updateProfileTypeOutput();
    };

    accountTypeRadios.forEach(function (radio) {
      radio.addEventListener("change", updateAccountTypeVisibility);
    });
    profileTypeChoiceRadios.forEach(function (radio) {
      radio.addEventListener("change", updateProfileTypeOutput);
    });
    updateAccountTypeVisibility();
  }

  if (contactEmailField && eventSwissEmailDisplay) {
    var updateEmailDisplay = function () {
      eventSwissEmailDisplay.textContent = contactEmailField.value.trim() || "—";
    };
    contactEmailField.addEventListener("input", updateEmailDisplay);
    updateEmailDisplay();
  }

  /* ---------- Pré-remplissage via Google Sign-In (nom/prénom/e-mail) ---------- */
  (function initGoogleSignIn() {
    var containers = document.querySelectorAll("[data-google-signin-btn]");
    if (!containers.length) return;

    var clientIdMeta = document.querySelector('meta[name="google-signin-client-id"]');
    var clientId = clientIdMeta ? clientIdMeta.content.trim() : "";
    if (!clientId) {
      // Pas encore configuré : on masque le bloc plutôt que d'afficher un
      // bouton cassé.
      containers.forEach(function (el) {
        var block = el.closest(".google-signin-block");
        if (block) block.style.display = "none";
      });
      return;
    }

    function decodeJwtPayload(token) {
      try {
        var base64Url = token.split(".")[1];
        var base64 = base64Url.replace(/-/g, "+").replace(/_/g, "/");
        var json = decodeURIComponent(
          atob(base64)
            .split("")
            .map(function (c) {
              return "%" + ("00" + c.charCodeAt(0).toString(16)).slice(-2);
            })
            .join("")
        );
        return JSON.parse(json);
      } catch (e) {
        return null;
      }
    }

    function handleCredentialResponse(response) {
      var payload = response && response.credential ? decodeJwtPayload(response.credential) : null;
      if (!payload) return;

      var form = document.querySelector("[data-ajax-form]");
      if (!form) return;

      var emailField = form.querySelector("[data-contact-email]");
      var formPrenomField = form.querySelector("#prenom");
      var formNomField = form.querySelector("#nom");

      if (emailField && payload.email && !emailField.value.trim()) {
        emailField.value = payload.email;
        emailField.dispatchEvent(new Event("input"));
      }
      if (formPrenomField && payload.given_name && !formPrenomField.value.trim()) {
        formPrenomField.value = payload.given_name;
      }
      if (formNomField && payload.family_name && !formNomField.value.trim()) {
        formNomField.value = payload.family_name;
      }
    }

    function renderButtons() {
      if (!window.google || !google.accounts || !google.accounts.id) return;
      google.accounts.id.initialize({
        client_id: clientId,
        callback: handleCredentialResponse,
      });
      containers.forEach(function (el) {
        google.accounts.id.renderButton(el, {
          type: "standard",
          theme: "outline",
          size: "large",
          shape: "pill",
          text: "continue_with",
          width: 320,
          locale: el.getAttribute("data-google-locale") || "fr",
        });
      });
    }

    if (window.google && google.accounts && google.accounts.id) {
      renderButtons();
    } else {
      // Le script accounts.google.com/gsi/client charge en async/defer :
      // on retente jusqu'à ce qu'il soit prêt (quelques centaines de ms max).
      var attempts = 0;
      var interval = setInterval(function () {
        attempts += 1;
        if (window.google && google.accounts && google.accounts.id) {
          clearInterval(interval);
          renderButtons();
        } else if (attempts > 40) {
          clearInterval(interval);
        }
      }, 100);
    }
  })();

  /* ---------- Validation & soumission AJAX ---------- */
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function setFieldError(form, fieldName, message) {
    var field = form.querySelector('[name="' + fieldName + '"]');
    if (!field) return;
    var wrapper = field.closest(".field");
    if (!wrapper) return;
    wrapper.classList.add("has-error");
    var errorEl = wrapper.querySelector(".field-error");
    if (errorEl) errorEl.innerHTML = message;
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

    var checkedAccountType = form.querySelector('input[name="account_type"]:checked');
    if (form.querySelector('input[name="account_type"]')) {
      if (!checkedAccountType) {
        setFieldError(form, "account_type", "Merci de sélectionner une option.");
        valid = false;
      } else if (checkedAccountType.value === "company") {
        var checkedProfileType = form.querySelector('input[name="profile_type_choice"]:checked');
        if (!checkedProfileType) {
          setFieldError(form, "profile_type_choice", "Merci de sélectionner une option.");
          valid = false;
        }
      }
    }

    var pwd = form.querySelector("#password");
    var pwdConfirm = form.querySelector("#password_confirm");
    if (pwd && pwdConfirm) {
      if (pwd.value.length < 8) {
        setFieldError(form, "password", "8 caractères minimum.");
        valid = false;
      } else if (pwd.value !== pwdConfirm.value) {
        setFieldError(form, "password_confirm", "Les mots de passe ne correspondent pas.");
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
            form.querySelectorAll('input[name="account_type"]').forEach(function (r) {
              r.dispatchEvent(new Event("change"));
            });
            if (eventSwissEmailDisplay) eventSwissEmailDisplay.textContent = "—";
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

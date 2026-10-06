// app.js - Console des licences ETDEL : confirmation des actions d'ecriture, copie dans le
//          presse-papiers, actions depliables, reglages replies ouverts sur un champ refuse,
//          champs du nouveau produit, duree par defaut, compteurs, controle d'exposition.
// ETDEL (c) 2026
(function () {
  'use strict';

  // Toute ecriture demande une confirmation ; sans JavaScript, le serveur affiche sa propre page.
  document.addEventListener('submit', function (evenement) {
    var formulaire = evenement.target;
    var message = formulaire.getAttribute('data-confirmer');
    if (!message || !formulaire.elements.confirme || formulaire.elements.confirme.value === '1') {
      return;
    }
    if (!window.confirm(message + ' ?')) {
      evenement.preventDefault();
      return;
    }
    formulaire.elements.confirme.value = '1';
  });

  document.addEventListener('click', function (evenement) {
    var retour = evenement.target.closest('[data-retour]');
    if (retour && window.history.length > 1) {
      evenement.preventDefault();
      window.history.back();
      return;
    }
    var bouton = evenement.target.closest('[data-copier]');
    if (!bouton) {
      return;
    }
    var cible = document.getElementById(bouton.getAttribute('data-copier'));
    if (!cible) {
      return;
    }
    var signaler = function () {
      bouton.textContent = 'Copie';
      window.setTimeout(function () { bouton.textContent = 'Copier'; }, 1500);
    };
    var secours = function () {
      var plage = document.createRange();
      plage.selectNodeContents(cible);
      var selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(plage);
      try {
        if (document.execCommand('copy')) {
          signaler();
        }
      } catch (e) {
        // Le texte reste selectionne : copie manuelle possible.
      }
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(cible.textContent).then(signaler, secours);
    } else {
      secours();
    }
  });

  // Fiche licence : une seule action depliee a la fois (toggle ne remonte pas : ecoute en capture).
  document.addEventListener('toggle', function (evenement) {
    var ouverte = evenement.target;
    if (!ouverte.open || !ouverte.classList || !ouverte.classList.contains('action')) {
      return;
    }
    Array.prototype.forEach.call(document.querySelectorAll('details.action[open]'), function (autre) {
      if (autre !== ouverte) {
        autre.open = false;
      }
    });
  }, true);

  // Champ refuse par le navigateur dans des reglages replies : on les deplie pour le montrer.
  // (invalid ne remonte pas : ecoute en capture.)
  document.addEventListener('invalid', function (evenement) {
    var replie = evenement.target.closest ? evenement.target.closest('details') : null;
    if (replie && !replie.open) {
      replie.open = true;
    }
  }, true);

  // Nouvelle distribution : le code et le nom du nouveau produit ne servent (et ne sont envoyes)
  // que si "Nouveau produit" est choisi ; ils sont alors obligatoires. Un fieldset desactive n'envoie
  // rien et le navigateur n'y verifie pas "required" : choisir un produit existant n'est jamais bloque.
  var produit = document.querySelector('select[data-produit]');
  var nouveau = document.querySelector('fieldset[data-nouveau-produit]');
  if (produit && nouveau) {
    var majProduit = function () {
      var aCreer = produit.value === '0';
      nouveau.hidden = !aCreer;
      nouveau.disabled = !aCreer;
      Array.prototype.forEach.call(nouveau.querySelectorAll('input'), function (champ) {
        champ.required = aCreer;
      });
    };
    produit.addEventListener('change', majProduit);
    majProduit();
  }

  // Creation d'une cle : la duree proposee suit la distribution choisie.
  var choix = document.querySelector('select[data-durees]');
  if (choix && choix.form && choix.form.elements.duree_j) {
    choix.addEventListener('change', function () {
      var option = choix.options[choix.selectedIndex];
      choix.form.elements.duree_j.value = option ? (option.getAttribute('data-duree') || '') : '';
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('textarea[maxlength]'), function (zone) {
    var compteur = document.createElement('small');
    compteur.className = 'discret';
    var majour = function () { compteur.textContent = zone.value.length + ' / ' + zone.getAttribute('maxlength'); };
    zone.parentNode.appendChild(compteur);
    zone.addEventListener('input', majour);
    majour();
  });

  // Controle d'exposition : aucun de ces fichiers ne doit etre telechargeable par URL.
  var liste = document.getElementById('exposition');
  if (liste && window.fetch) {
    // URL rebatie sans identifiants : fetch refuse une adresse qui en contient
    // (page ouverte par https://nom:mot@.../admin/).
    var base = window.location.origin + window.location.pathname;
    JSON.parse(liste.getAttribute('data-chemins') || '[]').forEach(function (chemin) {
      var ligne = document.createElement('li');
      ligne.textContent = chemin + ' : verification...';
      liste.appendChild(ligne);
      // Seule une reponse explicite du serveur prouve la protection : une erreur
      // reseau ou une redirection ne prouve rien et reste "non verifie".
      var conclure = function (statut) {
        if (statut === 200) {
          ligne.className = 'danger';
          ligne.textContent = chemin + ' : DANGER, telechargeable';
        } else if (statut === 401 || statut === 403 || statut === 404) {
          ligne.className = 'protege';
          ligne.textContent = chemin + ' : protege (' + statut + ')';
        } else {
          ligne.className = 'inconnu';
          ligne.textContent = chemin + ' : non verifie (' + (statut || 'pas de reponse') + '), a controler a la main';
        }
      };
      window.fetch(new URL(chemin, base).href, { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
        .then(function (reponse) { conclure(reponse.status); }, function () { conclure(0); });
    });
  }
}());

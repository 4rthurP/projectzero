
let user = null;
let currentNonce = getCookie('user_nonce');
let currentNonceExpirationDate = getCookie('user_nonce_expiration');
let cookie_userNonce = getCookie('user_nonce');
let cookie_nonceExpirationDate = getCookie('user_nonce_expiration');
let lastNonceRequest = null;

getNonce();
const csrftoken = getCookie('csrftoken');
let searchOngoing = false;

document.addEventListener('DOMContentLoaded', function() {
    //Check the menu state to restore it back to how the user wants it
    var isMenuCollapsed = localStorage.getItem('menuCollapsed');

    if (isMenuCollapsed === 'true') {
        hamburgerMenu(isMenuCollapsed)
    }
});

//////////////////////////////////////
// Async requests
//////////////////////////////////////
function makeRequest(url, method = 'GET', formData = {}) {
    const baseUrl = 'http://arpege.localhost/';
    url = baseUrl + url;
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url);
        if (method !== 'POST') {
            xhr.setRequestHeader('Content-Type', 'application/json');
        }
        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                // Check if the response contains new_nonce
                const response = JSON.parse(xhr.response);
                if(response.nonce) {
                    setNonce(response.nonce, response.expiration);
                } 
                if(!response.success) {
                    if (response.error === 'expired-nonce' || response.error === 'invalid-nonce' || response.error === 'used-nonce') {
                        currentNonce = null;
                        cookie_userNonce = null;
                        getNonce();
                        formData.append('nonce', currentNonce);
                        makeRequest(url, method, formData);
                    }
                }
                resolve(response); 
            } else {
                reject(xhr.statusText);
            }
        };
        xhr.onerror = function() {
            reject(xhr.statusText);
        };
        if (method === 'POST') {
            xhr.send(formData);
        } else {
            xhr.send();
        }
    });
}

function getRest(endpoint, params = {}, use_nonce = true) {
    var url = `rest.php?action=${endpoint}`;
    if(currentNonce != null && use_nonce) {
        url = url + `&nonce=${currentNonce}` ;
    }
    url = url + Object.entries(params).map(([key, value]) => `&${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
    return makeRequest(url, 'GET');
}

function postRest(formData) {
    formData.append('nonce', currentNonce);
    return makeRequest(`rest.php`, 'POST', formData);
}

function setNonce(nonce, expiration) {
    currentNonce = nonce;
    currentNonceExpirationDate = expiration;
    setCookie('user_nonce', nonce, 7);
    setCookie('user_nonce_expiration', expiration, 7);
}

function setCookie(name, value, days) {
    let expires = '';
    if (days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        expires = `; expires=${date.toUTCString()}`;
    }
    document.cookie = `${name}=${value || ''}${expires}; path=/`;
}

function getNonce() {
    if (lastNonceRequest != null && (Date.now() - lastNonceRequest) < 5000) {
        setTimeout(getNonce, 5000);
    } else {
        if(currentNonce && currentNonceExpirationDate) {
            let currentDate = new Date();
            let expiration = new Date(currentNonceExpirationDate);
            if (currentDate < expiration) {
                return;
            } else {
                currentNonce = null;
            }
        } 

        if(currentNonce == null) {
            getRest('nonce/get', {'credential': user_credential}).then(response => {
                lastNonceRequest = Date.now();
                if (response.status === 'success') {
                    currentNonce = response.new_nonce;
                    currentNonceExpirationDate = response.nonce_expiration;
                } else {
                    togglePopup(null, 'Error', response.error);
                    console.log(response.error);
                }
            });
        }
    }

    return null;
}

function getCookie(name) {
    let cookieValue = null;
    if (document.cookie && document.cookie !== '') {
        const cookies = document.cookie.split(';');
        for (let i = 0; i < cookies.length; i++) {
            const cookie = cookies[i].trim();
            // Does this cookie string begin with the name we want?
            if (cookie.substring(0, name.length + 1) === (name + '=')) {
                cookieValue = decodeURIComponent(cookie.substring(name.length + 1));
                break;
            }
        }
    }
    return cookieValue;
}

//////////////////////////////////////
// Form handling
//////////////////////////////////////
function confirmForm(event) {
    event.preventDefault();
    redirectPopupConfirmation(event, '', 'form', 'next_step')
}

function submitForm(event) {
    event.target.form.submit()
}

function confirmNextStep(event) {
    event.preventDefault();
    redirectPopupConfirmation(event, '', 'form', 'next_step')
}

function redirectPopupConfirmation(initial_event, url = null, redirection_type = 'form', confirm_type = 'next_step') {

    if(confirm_type == "next_step") {
        var confirmText = 'Yes, go to the next step';
        var cancelText = 'Stay in the current step';
        var titleText = 'Are you sure you want to continue ?';
        var messageText = 'Once you start the next step, you won\'t be able to go back.';
    } else if (confirm_type == "bypass") {
        var confirmText = 'Yes, bypass';
        var cancelText = 'Cancel';
        var titleText = 'Confirm bypassing this step ?';
        var messageText = '';
    } else {
        return null;
    }

    var popupFooter = document.getElementById('popup-footer');

    var confirmButton = document.createElement('button');
    confirmButton.textContent = confirmText;
    confirmButton.id = "popup-confirm-button";
    confirmButton.classList.add('cta');
    confirmButton.classList.add('cta-primary');
    if(redirection_type == 'form') {
        console.log(initial_event)
        confirmButton.addEventListener('click', function() {
            initial_event.target.form.submit()
        });
    } else if(redirection_type == 'link') {
        confirmButton.addEventListener('click', function(initial_event, url) {
            redirectto(initial_event, url, false)
        });
    }

    var cancelButton = document.createElement('button');
    cancelButton.textContent = cancelText;
    cancelButton.classList.add('cta');
    cancelButton.classList.add('cta-secondary');
    cancelButton.addEventListener('click', closePopup);

    popupFooter.appendChild(confirmButton);
    popupFooter.appendChild(cancelButton);

    togglePopup(null, titleText,messageText)

}

function loginForm(event) {
    event.preventDefault();

    var form = event.target;
    var formData = new FormData(form);

    postRest(formData).then(response => {
        if (response.status === 'success') {
            sessionStorage.setItem('user', JSON.stringify(response.user));
            window.location = 'index.php';
        } else {
            togglePopup(null, 'Error', response.error);
        }
    });
}

//////////////////////////////////////
// Handling Popups and notifs
//////////////////////////////////////
document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        let popups = document.getElementsByClassName('popup-container');
        for (let i = 0; i < popups.length; i++) {
            if(popups[i].style.display != 'none') {
                closePopup(popups[i].id)
            }
        }
    }
    // else if (event.key === 'Enter' & document.getElementById('popup-container') != "") {
    //     if(document.getElementById('popup-container').style.display != 'none') {
    //         var button = document.getElementById('popup-confirm-button');
    //         button.click()
    //     }
    // }
    // TODO: tab completion for search results
    if ((event.key === 'ArrowUp' || event.key === 'ArrowDown') & event.target.classList.contains('search-bar-input')) {
        let selectedSearchResult = event.target.parentElement.getElementsByClassName('list-group-item active')[0];
        let nextSearchResult = event.target.parentElement.getElementsByClassName('list-group-item')[0];
        if(selectedSearchResult) {
            if (event.key === 'ArrowDown') {
                newSearchResult = selectedSearchResult.nextElementSibling;
                if(!newSearchResult) {
                    console.log(nextSearchResult);
                }
            }
            selectedSearchResult.classList.remove('active');
            selectedSearchResult.removeAttribute('aria-current');

            if(event.key === 'ArrowUp') {
                newSearchResult = selectedSearchResult.previousElementSibling;
                if(!newSearchResult) {
                    newSearchResult = event.target.parentElement.getElementsByClassName('list-group-item')[event.target.parentElement.getElementsByClassName('list-group-item').length - 1];
                }
            }  
            if(newSearchResult){
                nextSearchResult = newSearchResult;
            }
        } 
        nextSearchResult.classList.add('active');
        nextSearchResult.setAttribute('aria-current', 'true');
    }
    else if (event.key === 'Enter' & event.target.classList.contains('search-bar-input')) {
        console.log(event.target)
        event.preventDefault();
        let activeSearchResult = event.target.parentElement.getElementsByClassName('list-group-item active')[0];
        if(activeSearchResult) {
            activeSearchResult.click();
        }
    }
});

function togglePopup(id = null, title = null, subtitle = null) {
    if(id == null) {
        id = 'main-popup';
    }

    var popup = document.getElementById(id);
    var popupBackground = document.getElementById('popup-background');

    let popup_title = popup.getElementsByClassName('popup-title')[0];
    let popup_subtitle = popup.getElementsByClassName('popup-subtitle')[0];

    
    if(popup.style.display == 'none' || popup.style.display == '') {
        if(popup_title && title) {
            popup_title.innerHTML = title;
        }
        if(popup_subtitle && subtitle) {
            popup_subtitle.innerHTML = subtitle;
        }
        popup.style.display = 'flex';
        popupBackground.style.display = 'block';
        popupBackground.addEventListener('click', function() {
            togglePopup(id)
        });
    } else {
        popup.style.display = 'none';
        popupBackground.style.display = 'none';
    }


}

function closePopup(popup_id = null) {

    if(popup_id != null) {
        var popup = document.getElementById(popup_id);
        var close_button = popup.getElementsByClassName('popup-close')[0]
    } else {
        var popup = document.getElementById('popup-container');
        var close_button = document.getElementById('popup-close')
    }    
    
    if(close_button.classList.contains('close-refresh')) {
        location.reload()
    }
    
    let popup_title = popup.getElementsByClassName('popup-title')[0];
    if(popup_title) {
        popup_title.innerHTML = null;
    }

    let popup_content = popup.getElementsByClassName('popup-content')[0];
    if(popup_content) {
        popup_content.innerHTML = null;
    }

    let popup_subtitle = popup.getElementsByClassName('popup-subtitle')[0];
    if(popup_subtitle) {
        popup_subtitle.innerHTML = null;
    }

    let popup_footer = popup.getElementsByClassName('popup-footer')[0];
    if(popup_footer) {
        popup_footer.innerHTML = null;
    }

    popup.style.display = 'none';
    document.getElementById('popup-background').style.display = 'none';
}

function showNotif(type, title, message) {
    let notif_container = document.getElementById('notif-container');
    let notif = document.createElement('div');
    notif.classList.add('notif');
    notif.classList.add(type);
    notif.innerHTML = `<h4>${title}</h4><p>${message}</p>`;
    notif_container.appendChild(notif);
    notif.addEventListener('click', closeNotif);
    setTimeout(function() {
        removeNotif(notif);
    }, 5000);
}

function closeNotif(event) {
    event.preventDefault();

    notif = event.target.parentElement;

    removeNotif(notif);
    
}

function removeNotif(notif) {
    
    parent = notif.parentElement;
    parent.remove();

    var currentURL = new URL(window.location.href);
    var paramsURL = new URLSearchParams(currentURL.search);
    paramsURL.delete('success');
    paramsURL.delete('error');
    window.history.replaceState(null, null, currentURL.pathname + '?' + paramsURL.toString());

}

//////////////////////////////////////
// Misc
//////////////////////////////////////
function redirectto(event, url, confirmPopup = false, confirmType = null) {
    event.preventDefault();

    if(confirmPopup) {
        redirectPopupConfirmation(event, url, 'link', confirmType)
    } else {
        window.location = url
    }
}

function displayProgressBar() {  
    var popupContent = document.getElementById('popup-content');

    var progressContainer = document.createElement('div');
    progressContainer.classList.add('progress');
    var progressBar = document.createElement('div');
    progressBar.classList.add('progress-bar');
    progressBar.id = 'progressbar';
    progressBar.style.width = "10%";
    var progressMessage = document.createElement('div');
    progressMessage.id = 'progressbar-message';
    var progressText = document.createElement('p');
    progressText.id = 'progressbar-message-text';
    progressText.innerHTML = 'Work is starting';
    var progressArticles = document.createElement('p');
    progressArticles.innerHTML = ' - Articles loaded: ';
    var progressNumberArticles = document.createElement('p');
    progressNumberArticles.id = 'progressbar-message-number';
    progressNumberArticles.innerHTML = '0';
    var progressArticlesSlash = document.createElement('p');
    progressArticlesSlash.innerHTML = '/';
    var progressTotalArticles = document.createElement('p');
    progressTotalArticles.id = 'progressbar-message-total';

    progressMessage.appendChild(progressText)
    progressMessage.appendChild(progressArticles)
    progressMessage.appendChild(progressNumberArticles)
    progressMessage.appendChild(progressArticlesSlash)
    progressMessage.appendChild(progressTotalArticles)

    progressContainer.appendChild(progressBar)
    popupContent.appendChild(progressMessage)
    popupContent.appendChild(progressContainer)

    togglePopup(null, 'We are searching for your articles...','Informations about the articles that you listed and their abstract are being retrieved. Please wait for a bit.')

}

function hamburgerMenu(collapse = false) {
    var menu = document.getElementById('menu');
    var content = document.getElementById('main-content');

    var menu_size = 64;

    if(menu.classList.contains('menu-closed') & collapse == false) {
        menu.classList.remove('menu-closed');
        menu_size = 250;

        // Store menu state in localStorage
        localStorage.setItem('menuCollapsed', 'false');
    } else {
        menu.classList.add('menu-closed');

        // Store menu state in localStorage
        localStorage.setItem('menuCollapsed', 'true');

    }
    menu.style.setProperty('--menu-width', menu_size + 'px');
    content.style.marginLeft = menu_size + 'px';

    var resize = menu_size + 96;
    content.style.width = 'calc(100% - '+resize+'px)';
}

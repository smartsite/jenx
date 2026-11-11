"use strict";

(() => {
    const API_LIST = "/api/build-list.php";
    const API_STATUS = "/api/build-status.php?request_id=";
    const REFRESH_INTERVAL = 30000;

    const ACTIVE_STATES = new Set([
        "PREPARED",
        "DISPATCHING",
        "DISPATCHED",
        "RUNNING",
        "VERIFYING"
    ]);

   const FAILED_STATES = new Set([
        "DISPATCH_REJECTED",
        "BUILD_FAILED",
        "RESULT_REJECTED"
    ]);

    const RECONCILIATION_STATES = new Set([
        "RECONCILIATION_REQUIRED"
    ]);

    const API_DEPLOYMENT_STATUS = "/api/deployment-status.php";
    const API_DEPLOYMENT_ACTION = "/api/deployment-action.php";

    const elements = {
        connection: document.querySelector("#connection"),
        refresh: document.querySelector("#refresh"),
        total: document.querySelector("#total-builds"),
        successful: document.querySelector("#successful-builds"),
        active: document.querySelector("#active-builds"),
        attention: document.querySelector("#attention-builds"),
        lastUpdated: document.querySelector("#last-updated"),
        search: document.querySelector("#search"),
        statusFilter: document.querySelector("#status-filter"),
        typeFilter: document.querySelector("#type-filter"),
        error: document.querySelector("#error-message"),
        rows: document.querySelector("#build-rows"),
        recordCount: document.querySelector("#record-count"),
        detailPanel: document.querySelector("#detail-panel"),
        detailTitle: document.querySelector("#detail-title"),
        detailContent: document.querySelector("#detail-content"),
        resultSection: document.querySelector("#result-section"),
        resultContent: document.querySelector("#result-content"),
        closeDetail: document.querySelector("#close-detail"),
        navLinks: document.querySelectorAll(".nav-link"),
        deploymentCards: document.querySelectorAll(".deployment-card"),
        deploymentHistory: document.querySelector("#deployment-history"),
        views: {
            releases: document.querySelector("#view-releases"),
            deployments: document.querySelector("#view-deployments"),
            history: document.querySelector("#view-history")
        },
    };

    let builds = [];
    let selectedRequestId = null;
    let selectedBuild = null;
    let loading = false;

    function formatDate(value) {
        if (!value) return "—";

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) return value;

        return new Intl.DateTimeFormat(undefined, {
            year: "numeric",
            month: "short",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            timeZoneName: "short"
        }).format(date);
    }

    function formatAge(value) {
        if (!value) return "Unknown";
        const timestamp = new Date(value).getTime();
        if (Number.isNaN(timestamp)) return "Unknown";
        const seconds = Math.max(0, Math.floor(
            (Date.now() - timestamp) / 1000
        ));

        if (seconds < 60) return `${seconds}s`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes}m`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours}h ${minutes % 60}m`;
        const days = Math.floor(hours / 24);
        return `${days}d ${hours % 24}h`;
    }

    function shortCommit(value) {
        return typeof value === "string" ? value.slice(0, 12) : "—";
    }

    function statusClass(status) {
        if (status === "SUCCEEDED") return "status-success";
        if (RECONCILIATION_STATES.has(status)) {
            return "status-reconciliation";
        }
        if (FAILED_STATES.has(status)) return "status-failure";
        if (ACTIVE_STATES.has(status)) return "status-active";
        return "status-neutral";
    }

    function setConnection(message) {
        elements.connection.textContent = message;
    }

    function showError(message) {
        elements.error.textContent = message;
        elements.error.hidden = false;
    }

    function clearError() {
        elements.error.textContent = "";
        elements.error.hidden = true;
    }

    function createElement(tag, className, text) {
        const element = document.createElement(tag);

        if (className) element.className = className;
        if (text !== undefined) element.textContent = String(text);

        return element;
    }

    function addDetail(container, label, value, options = {}) {
        const item = createElement("dl", "detail-item");
        item.append(createElement("dt", "", label));

        const dd = createElement("dd");

        if (options.href) {
            const link = createElement("a", "", value);
            link.href = options.href;
            link.target = "_blank";
            link.rel = "noopener noreferrer";
            dd.append(link);
        } else if (options.code) {
            dd.append(createElement("code", "", value));
        } else {
            dd.textContent = value === null || value === undefined || value === ""
                ? "—"
                : String(value);
        }

        item.append(dd);
        container.append(item);
    }

    function renderSummary() {
        elements.total.textContent = String(builds.length);
        elements.successful.textContent = String(
            builds.filter(build => build.status === "SUCCEEDED").length
        );
        elements.active.textContent = String(
            builds.filter(build => ACTIVE_STATES.has(build.status)).length
        );
        document.querySelector("#failed-builds").textContent = String(
            builds.filter(build => FAILED_STATES.has(build.status)).length
        );
        document.querySelector("#reconciliation-builds").textContent = String(
            builds.filter(build =>
                RECONCILIATION_STATES.has(build.status)
            ).length
        );
    }

    function getFilteredBuilds() {
        const search = elements.search.value.trim().toLowerCase();
        const status = elements.statusFilter.value;
        const type = elements.typeFilter.value;

        return builds.filter(build => {
            if (status && build.status !== status) return false;

            if (type === "test" && !build.test_only) return false;
            if (type === "release" && build.test_only) return false;

            if (search) {
                const searchable = [
                    build.release_tag,
                    build.expected_source_commit,
                    build.request_id,
                    build.status
                ].join(" ").toLowerCase();

                if (!searchable.includes(search)) return false;
            }

            return true;
        });
    }

    function getSelectedVerifiedDigest() {
        if (
            selectedBuild
            && selectedBuild.status === "SUCCEEDED"
            && selectedBuild.result
            && typeof selectedBuild.result.digest === "string"
            && /^sha256:[a-f0-9]{64}$/.test(selectedBuild.result.digest)
        ) {
            return selectedBuild.result.digest;
        }

        return null;
    }

    function deploymentActionConfirm(message) {
        return window.confirm(message);
    }

    async function runDeploymentAction(payload) {
        const response = await fetch(API_DEPLOYMENT_ACTION, {
            method: "POST",
            cache: "no-store",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            body: JSON.stringify(payload)
        });

        let data;

        try {
            data = await response.json();
        } catch (error) {
            throw new Error(
                `Deployment action returned HTTP ${response.status} without valid JSON.`
            );
        }

        if (!response.ok || data.ok !== true) {
            const message =
                data?.error?.message
                || `Deployment action failed (HTTP ${response.status}).`;

            throw new Error(message);
        }

        return data;
    }

    async function handleDeploymentAction(payload, confirmationMessage) {
        if (
            confirmationMessage
            && !deploymentActionConfirm(confirmationMessage)
        ) {
            return;
        }

        clearError();

        elements.deploymentCards.forEach(card => {
            card.querySelectorAll(".deployment-action").forEach(button => {
                button.disabled = true;
            });
        });

        setConnection("Deployment in progress…");

        try {
            const data = await runDeploymentAction(payload);

            setConnection("Connected");

            const operation = data.operation || {};
            const operationId = operation.operation_id || "unknown";

            window.alert(
                `${String(payload.action).toUpperCase()} succeeded.\n\n` +
                `Environment: ${operation.target_environment || "—"}\n` +
                `Operation: ${operationId}`
            );

            await loadDeploymentStatus();
        } catch (error) {
            setConnection("Connected");
            showError(error.message || "Deployment action failed.");
            window.alert(
                `Deployment action failed.\n\n${error.message || "Unknown error."}`
            );
        } finally {
            elements.deploymentCards.forEach(card => {
                card.querySelectorAll(".deployment-action").forEach(button => {
                    button.disabled = false;
                });
            });
        }
    }

    function createDeploymentActionButton(label, payload, confirmationMessage, disabled = false) {
        const button = createElement("button", "button secondary deployment-action", label);
        button.type = "button";
        button.disabled = disabled;

        if (disabled) {
            button.title = "Select a verified successful release first.";
        }

        button.addEventListener("click", () => {
            handleDeploymentAction(payload, confirmationMessage);
        });

        return button;
    }

    function renderDeploymentActions(environment) {
        const card = document.querySelector(
            `.deployment-card[data-environment="${environment.environment}"]`
        );

        if (!card) {
            return;
        }

        let actions = card.querySelector(".deployment-actions");

        if (!actions) {
            actions = createElement("div", "deployment-actions");
            card.append(actions);
        }

        actions.replaceChildren();

        const digest = getSelectedVerifiedDigest();

        if (environment.environment === "dev") {
            actions.append(
                createDeploymentActionButton(
                    "Deploy",
                    {
                        action: "deploy",
                        environment: "dev",
                        digest: digest || ""
                    },
                    digest
                        ? `Deploy ${selectedBuild.release_tag || "the selected release"} to DEV?`
                        : null,
                    !digest
                )
            );
        }

        if (environment.environment === "uat") {
            actions.append(
                createDeploymentActionButton(
                    "Deploy",
                    {
                        action: "deploy",
                        environment: "uat",
                        digest: digest || ""
                    },
                    digest
                        ? `Deploy ${selectedBuild.release_tag || "the selected release"} to UAT?`
                        : null,
                    !digest
                )
            );

            actions.append(
                createDeploymentActionButton(
                    "Promote DEV → UAT",
                    {
                        action: "promote",
                        source: "dev",
                        target: "uat"
                    },
                    "Promote the current verified DEV deployment to UAT?"
                )
            );
        }

        if (environment.environment === "prod") {
            actions.append(
                createDeploymentActionButton(
                    "Deploy",
                    {
                        action: "deploy",
                        environment: "prod",
                        digest: digest || "",
                        confirm_prod: true
                    },
                    digest
                        ? `Deploy ${selectedBuild.release_tag || "the selected release"} to PRODUCTION?`
                        : null,
                    !digest
                )
            );

            actions.append(
                createDeploymentActionButton(
                    "Promote UAT → PROD",
                    {
                        action: "promote",
                        source: "uat",
                        target: "prod",
                        confirm_prod: true
                    },
                    "Promote the current verified UAT deployment to PRODUCTION?"
                )
            );

            actions.append(
                createDeploymentActionButton(
                    "Rollback",
                    {
                        action: "rollback",
                        environment: "prod",
                        confirm_prod: true
                    },
                    "Rollback PRODUCTION to its previous verified deployment?"
                )
            );
        }
    }

    function renderDeployments(data) {
        const environments = Array.isArray(data.environments)
            ? data.environments
            : [];

        environments.forEach(environment => {
            const card = document.querySelector(
                `.deployment-card[data-environment="${environment.environment}"]`
            );

            if (!card) {
                return;
            }

            const status = card.querySelector(".deployment-status");
            const details = card.querySelector(".deployment-details");

            if (!status || !details) {
                return;
            }

            const health = environment.environment_health || "unknown";
            const initialized = environment.initialized === true;
            const desired = environment.desired || {};
            const observed = environment.observed || {};

            status.textContent = formatDeploymentStatus(health);
            status.className =
                `deployment-status ${deploymentStatusClass(health)}`;

            const digest = observed.digest || desired.digest || "—";

            const currentDeployment =
                Array.isArray(environment.deployments) &&
                environment.deployments.length > 0
                ? environment.deployments[0]
                : null;

            const release = currentDeployment?.release_tag || "—";
            const sourceCommit = currentDeployment?.source_commit || "—";

            details.innerHTML = `
            <div>
                <span>Release</span>
                <strong>${escapeHtml(release)}</strong>
            </div>
            <div>
                <span>Source commit</span>
                <code>${escapeHtml(
                    sourceCommit === "—"
                        ? "—"
                        : sourceCommit.substring(0, 8)
                )}</code>
            </div>
            <div>
                 <span>Image digest</span>
                 <code>${escapeHtml(initialized ? digest : "—")}</code>
            </div>
            <div>
                <span>Health</span>
                <strong class="${health === "healthy" ? "health-ok" : ""}">
                    ${escapeHtml(deploymentHealthLabel(environment))}
                </strong>
            </div>
        `;

            renderDeploymentActions(environment);
        });

        renderDeploymentHistory(environments);
    }

    function formatDeploymentStatus(health) {
        switch (health) {
            case "healthy":
                return "Running";
            case "unhealthy":
                return "Unhealthy";
            case "not_deployed":
                return "Not deployed";
            default:
                return "Unknown";
        }
    }

    function deploymentStatusClass(health) {
        switch (health) {
            case "healthy":
                return "status-running";
            case "not_deployed":
                return "status-pending";
            case "unhealthy":
                return "status-failed";
            default:
                return "status-pending";
        }
    }

    function deploymentHealthLabel(environment) {
        if (!environment.initialized) {
            return "Not deployed";
        }

        return environment.observed?.health || "Unknown";
    }

    function renderDeploymentHistory(environments) {
        if (!elements.deploymentHistory) {
            return;
        }

        const deployments = [];

        environments.forEach(environment => {
            const history = Array.isArray(environment.deployments)
                ? environment.deployments
                : [];

            history.forEach(deployment => {
                deployments.push({
                    ...deployment,
                    environment: environment.environment
                });
            });
        });

        if (deployments.length === 0) {
            elements.deploymentHistory.innerHTML =
                '<p class="muted">No deployment history available yet.</p>';
            return;
        }

        deployments.sort((a, b) =>
            String(b.created_at || "").localeCompare(String(a.created_at || ""))
        );

        elements.deploymentHistory.innerHTML = deployments.map(deployment => `
            <div class="history-row">
                <strong>${escapeHtml(deployment.environment.toUpperCase())}</strong>
                <span>${escapeHtml(deployment.release_tag || "Unknown release")}</span>
                <code>${escapeHtml(deployment.digest || "—")}</code>
            </div>
        `).join("");
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    function renderBuilds() {
        const filtered = getFilteredBuilds();
        elements.rows.replaceChildren();

        if (filtered.length === 0) {
            const row = document.createElement("tr");
            const cell = createElement(
                "td",
                "empty-state",
                builds.length ? "No builds match these filters." : "No build records found."
            );

            cell.colSpan = 5;
            row.append(cell);
            elements.rows.append(row);
        } else {
            filtered.forEach(build => {
                const row = document.createElement("tr");

                if (build.request_id === selectedRequestId) {
                    row.classList.add("selected");
                }

                const statusCell = document.createElement("td");
                statusCell.append(
                    createElement("span", `status ${statusClass(build.status)}`, build.status)
                );

                const tagCell = document.createElement("td");
                const selectButton = createElement(
                    "button",
                    "build-select",
                    build.release_tag || "(no tag)"
                );
                selectButton.type = "button";
                selectButton.addEventListener("click", () => selectBuild(build.request_id));
                tagCell.append(selectButton);

                const typeCell = createElement(
                    "td",
                    "type-label",
                    build.test_only ? "Test only" : "Release"
                );

                const updatedCell = document.createElement("td");
                updatedCell.append(
                    createElement("div", "", formatDate(build.updated_at)),
                    createElement("div", "muted small",
                        `${formatAge(build.updated_at)} in current state`)
                );

                const commitCell = createElement(
                    "td",
                    "commit",
                    shortCommit(build.expected_source_commit)
                );

                row.append(statusCell, tagCell, typeCell, updatedCell, commitCell);
                elements.rows.append(row);
            });
        }

        elements.recordCount.textContent =
            `Showing ${filtered.length} of ${builds.length} loaded build records.`;
    }

    async function loadBuilds() {
        if (loading) return;

        loading = true;
        elements.refresh.disabled = true;
        setConnection("Refreshing…");

        try {
            const response = await fetch(API_LIST, {
                method: "GET",
                cache: "no-store",
                headers: { Accept: "application/json" }
            });

            if (!response.ok) {
                throw new Error(`Build list returned HTTP ${response.status}.`);
            }

            const data = await response.json();

            if (!data.ok || !Array.isArray(data.builds)) {
                throw new Error("The build-list response was not valid.");
            }

            builds = data.builds;
            renderSummary();
            renderBuilds();
            loadDeploymentStatus();

            elements.lastUpdated.textContent =
                `Updated ${new Date().toLocaleTimeString()}`;

            clearError();
            setConnection("Connected");
        } catch (error) {
            setConnection("Connection error");
            showError(error.message || "Unable to load build records.");
        } finally {
            loading = false;
            elements.refresh.disabled = false;
        }

        if (selectedRequestId && builds.some(
            build => build.request_id === selectedRequestId
        )) {
            await selectBuild(selectedRequestId, false);
        }
    }

    async function selectBuild(requestId, scroll = true) {
        selectedRequestId = requestId;
        renderBuilds();

        elements.detailPanel.hidden = false;
        elements.detailTitle.textContent = "Loading build details…";
        elements.detailContent.replaceChildren();
        elements.resultContent.replaceChildren();
        elements.resultSection.hidden = true;

        if (scroll) {
            elements.detailPanel.scrollIntoView({
                behavior: "smooth",
                block: "start"
            });
        }

        try {
            const response = await fetch(
                API_STATUS + encodeURIComponent(requestId),
                {
                    method: "GET",
                    cache: "no-store",
                    headers: { Accept: "application/json" }
                }
            );

            if (!response.ok) {
                throw new Error(`Build status returned HTTP ${response.status}.`);
            }

            const data = await response.json();

            if (!data.ok || !data.build) {
                throw new Error("The build-status response was not valid.");
            }

            // Ignore a response if the user selected another build meanwhile.
            if (selectedRequestId !== requestId) return;

            const build = data.build;
            selectedBuild = build;

            elements.detailTitle.textContent =
                `${build.release_tag} · ${build.status}`;

            renderDeploymentActionsForAllCards();

            addDetail(elements.detailContent, "Request ID", build.request_id, {
                code: true
            });
            addDetail(elements.detailContent, "Status", build.status);
            addDetail(elements.detailContent, "Build type",
                build.test_only ? "Test only" : "Release");
            addDetail(elements.detailContent, "Source branch", build.source_branch);
            addDetail(elements.detailContent, "Expected commit",
                build.expected_source_commit, { code: true });
            addDetail(elements.detailContent, "Created", formatDate(build.created_at));
            addDetail(elements.detailContent, "Last updated", formatDate(build.updated_at));
            addDetail(
                elements.detailContent,
                "Time in current state",
                formatAge(build.updated_at)
            );

            const result = build.result;

            if (build.status === "SUCCEEDED" && result) {
                elements.resultSection.hidden = false;

                addDetail(elements.resultContent, "Image", result.image, {
                    code: true
                });
                addDetail(elements.resultContent, "Digest", result.digest, {
                    code: true
                });

                const runId = String(result.workflow_run_id || "");

                if (/^\d+$/.test(runId)) {
                    addDetail(
                        elements.resultContent,
                        "GitHub Actions run",
                        runId,
                        {
                            href: `https://github.com/HUMNN-INNOVATIONS/foundd/actions/runs/${runId}`
                        }
                    );
                } else {
                    addDetail(elements.resultContent, "GitHub Actions run", "—");
                }
            }
        } catch (error) {
            if (selectedRequestId === requestId) {
                elements.detailTitle.textContent = "Build details unavailable";
                addDetail(
                    elements.detailContent,
                    "Error",
                    error.message || "Unable to load build details."
                );
            }
        }
    }

    function renderDeploymentActionsForAllCards() {
        elements.deploymentCards.forEach(card => {
            const environment = card.dataset.environment;

            renderDeploymentActions({
                environment
            });
        });
    }

    async function loadDeploymentStatus() {
        try {
            const response = await fetch(API_DEPLOYMENT_STATUS, {
                cache: "no-store"
            });

            if (!response.ok) {
                throw new Error(`Deployment status request failed (${response.status})`);
            }

            const data = await response.json();
            renderDeployments(data);
            renderDeploymentActionsForAllCards();

        } catch (error) {
            console.error("Failed to load deployment status:", error);
        }
    }

    function selectView(viewName) {
        Object.entries(elements.views).forEach(([name, view]) => {
            view.hidden = name !== viewName;
        });

        elements.navLinks.forEach(link => {
            link.classList.toggle(
                "active",
                link.dataset.view === viewName
            );
        });
    }

    elements.navLinks.forEach(link => {
        link.addEventListener("click", () => {
            selectView(link.dataset.view);
        });
    });

    elements.refresh.addEventListener("click", loadBuilds);
    elements.search.addEventListener("input", renderBuilds);
    elements.statusFilter.addEventListener("change", renderBuilds);
    elements.typeFilter.addEventListener("change", renderBuilds);

    elements.closeDetail.addEventListener("click", () => {
        selectedRequestId = null;
        selectedBuild = null;
        elements.detailPanel.hidden = true;
        renderBuilds();
        renderDeploymentActionsForAllCards();
    });

    loadBuilds();
    window.setInterval(loadBuilds, REFRESH_INTERVAL);
})();

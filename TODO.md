1 | To Do:
2 |
3 | _ Generate prettier reports -- html maybe? Easier to view tables that way anyways. (Low)
4 | _ Pull time entries from Harvest (maybe Gusto as well?) to compare against what we've detected to find unlogged time. (Medium)
5 | _ Pull activity on Issues from Github to discern comments and interactions there. (High)
6 | _ Pull data from local Slack cache to find comments and interactions made by me. (Medium)
7 | _ Interact with ClickUp. (High)
8 |
9 | _ Add interaction with vLLM. (High)
10 |
11 | ## Implementation Plan
12 |
13 | ### 1. HTML Report Generation
14 | - [ ] Analyze current renderers (Markdown/JSON/TSV) (Low)
15 | - [ ] Create HTML template structure (Low)
16 | - [ ] Implement table rendering with CSS styling (Medium)
17 | - [ ] Add interactive filters/sorting (High)
18 |
19 | ### 2. Time Tracking Integrations
20 | - [ ] Setup API config in config.json (Low)
21 | - [ ] Implement Harvest API integration (Medium)
22 | - [ ] Add Gusto API support (if needed) (High)
23 | - [ ] Create comparison logic for unlogged time (Medium)
24 |
25 | ### 3. GitHub Activity Integration
26 | - [ ] Parse GitHub API for issue comments (Low)
27 | - [ ] Add event classification for GitHub interactions (Medium)
28 | - [ ] Implement time attribution for code reviews (High)
29 |
30 | ### 4. Slack Data Integration
31 | - [ ] Parse local Slack cache files (Low)
32 | - [ ] Extract message metadata (Medium)
33 | - [ ] Add Slack activity classification (High)
34 |
35 | ### 5. ClickUp Integration
36 | - [ ] Implement API access (Low)
37 | - [ ] Parse task timelines (Medium)
38 | - [ ] Add time tracking correlation (High)
39 |
40 | ### 6. vLLM Integration
41 | - [ ] Setup API connection (Low)
42 | - [ ] Implement model interaction patterns (Medium)
43 | - [ ] Add usage tracking (High)
44 |
45 | ## Additional Ideas
46 |
47 | _ Add PDF export option (Medium)
48 | _ Implement time anomaly detection (High)
49 | _ Create interactive timeline visualization (High)
50 | _ Add support for Jira time tracking (Medium)
51 | _ Implement automatic sync scheduling (Low)
52 | _ Add machine learning for pattern recognition (High)

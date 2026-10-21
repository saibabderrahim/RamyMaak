
# DESIGN SYSTEM — ERP / B2B SAAS

## 1. Product identity

Product type: Operational ERP / B2B SaaS
Design principles:
- Functional, precise and calm.
- Information-first, decoration-second.
- Consistent across all modules.
- Dense enough for daily professional use.
- Accessible and predictable.

Do not imitate a generic dashboard template.
Adapt the interface to the actual business workflow.

## 2. Visual foundation

Use CSS variables as the source of truth.
Map them to the existing frontend framework when needed.

Suggested neutral palette:
- App background: #F5F6F8
- Main surface: #FFFFFF
- Primary text: #202733
- Secondary text: #667085
- Borders: #E4E7EC
- Primary action: #2457C5

Semantic colors:
- Success: #18794E
- Warning: #946200
- Danger: #B42318
- Informational: #175CD3

Rules:
- Use neutral surfaces for most of the interface.
- Reserve strong colors for actions and meaningful states.
- Never communicate status through color alone.
- Do not introduce gradients by default.

## 3. Typography

- Use the existing project font when appropriate.
- Use a consistent type scale.
- Page title: clear, compact and prominent.
- Section headings: distinct but restrained.
- Table content: readable at operational density.
- Secondary metadata: smaller, never too faint.
- Use tabular numerals for aligned numeric columns when supported.

## 4. Spacing and geometry

Use a consistent spacing scale:
4, 8, 12, 16, 24, 32, 40, 48 px.

Suggested defaults:
- Form field gap: 16 px
- Section gap: 24 px
- Page content padding: 24 px
- Control height: 36–40 px
- Small radius: 4 px
- Standard radius: 6–8 px

Avoid large radii on every component.
Avoid arbitrary spacing values when a token already exists.

## 5. Application shell

- Navigation should reflect the product's information architecture.
- Make the current module and active navigation item obvious.
- Keep the page title and primary action easy to find.
- Use breadcrumbs when they improve orientation.
- Keep global navigation consistent between modules.
- Avoid oversized headers that waste operational screen space.

## 6. Tables and operational lists

- Use clear column labels and consistent alignment.
- Left-align text; right-align quantities and monetary values where appropriate.
- Keep row heights compact but readable.
- Use restrained row separators.
- Make sorting and filtering discoverable.
- Keep important identifiers and statuses visible.
- Avoid excessive badges and decorative icons.
- Provide clear pagination and empty states.
- Handle long text, narrow screens and large datasets deliberately.

## 7. Forms and actions

- Always provide explicit labels.
- Group fields by business meaning.
- Show validation close to the relevant field.
- Distinguish primary, secondary and destructive actions.
- Explain disabled actions when the reason is not obvious.
- Preserve user input after recoverable errors.
- Do not rely on placeholder text as the only label.

## 8. States

Every data-driven page must consider:
- Loading
- Empty
- Error
- Success
- Disabled
- Permission denied
- Partial or stale data, when relevant

Each state must explain what happened and what the user can do next.

## 9. Responsive behavior and accessibility

- Define behavior for desktop, tablet and mobile.
- Do not shrink a complex table until it becomes unreadable.
- Choose deliberately between horizontal scrolling, column prioritization
  and an alternative mobile layout.
- Preserve keyboard navigation and visible focus.
- Use accessible labels and sufficient contrast.
- Respect reduced-motion preferences.

## 10. Component governance

Before creating a component:
1. Search for an existing equivalent.
2. Reuse it if its behavior and semantics fit.
3. Extend it only when the extension is reusable.
4. Document new shared patterns.
5. Avoid multiple visual variants without a clear purpose.

## 11. Definition of visual done

A page is not complete until:
- It follows the design tokens.
- The hierarchy and information density fit its business task.
- All visible controls have coherent behavior.
- Loading, empty and error states are addressed.
- Desktop and mobile layouts have been reviewed.
- The rendered interface has been inspected when browser access is available.
- No known visual regression remains unexplained.
